<?php
declare(strict_types=1);

namespace App\Services;

use App\Bootstrap;
use App\Clients\AsaasClient;
use App\Clients\NotazzClient;
use App\Helpers\Logger;
use App\Helpers\Security;
use App\Helpers\CustomerEnricher;
use App\Helpers\ValueSplitter;
use App\Repositories\DocumentRepo;
use App\Repositories\PaymentRepo;
use App\Repositories\ProductRepo;
use App\Repositories\CompanyRepo;
use App\Database;

final class EmissionQueue
{
    /** Clientes Notazz por empresa (cada empresa tem sua API key). */
    private array $clients = [];

    private function clientFor(?array $company): NotazzClient
    {
        $companyId = (int)($company['id'] ?? 1);
        if (!isset($this->clients[$companyId])) {
            $this->clients[$companyId] = new NotazzClient($company['notazz_api_key'] ?? null);
        }
        return $this->clients[$companyId];
    }

    public function processBatch(): array
    {
        $maxAttempts  = (int)Bootstrap::config('queue.max_attempts', 5);
        $batchSize    = (int)Bootstrap::config('queue.batch_size', 10);
        $retryBackoff = (array)Bootstrap::config('queue.retry_backoff', [60,300,900,3600,21600]);
        // Pausa entre chamadas à Notazz (a API bloqueia acima de ~100 req/min).
        $paceUs       = max(0, (int)Bootstrap::config('queue.pace_ms', 800)) * 1000;

        $docs = DocumentRepo::fetchAndLockNextBatch($batchSize);
        $results = ['processed' => 0, 'sent' => 0, 'errors' => 0, 'ignored' => 0];

        foreach ($docs as $doc) {
            $results['processed']++;
            try {
                $payment = PaymentRepo::find((int)$doc['payment_row_id']);
                if (!$payment) {
                    DocumentRepo::markIgnored((int)$doc['id'], 'payment_not_found');
                    $results['ignored']++;
                    continue;
                }
                $product = $doc['product_id'] ? ProductRepo::find((int)$doc['product_id']) : null;
                if (!$product) {
                    DocumentRepo::markIgnored((int)$doc['id'], 'product_not_found');
                    $results['ignored']++;
                    continue;
                }

                // Empresa do documento (multi-empresa): define CNPJ emissor, conta Asaas, split e fiscal.
                $companyId = (int)($doc['company_id'] ?? ($product['company_id'] ?? 1));
                $company   = CompanyRepo::find($companyId) ?: null;

                // Enriquece o cliente via API Asaas DA EMPRESA (cada empresa tem sua conta) se veio só como ID
                $payment = CustomerEnricher::ensure($payment, $company['asaas_api_token'] ?? null);

                // Cliente sem CPF/CNPJ (provável estrangeiro) e sem país definido: não adianta
                // retentar (a Notazz exige um ou outro). Vai para Revisão manual, onde o
                // financeiro define o país/documento na tela da nota e reemite.
                $taxIdCheck   = preg_replace('/\D+/', '', (string)($payment['customer_cpfcnpj'] ?? ''));
                $countryCheck = strtoupper(trim((string)($payment['customer_country'] ?? '')));
                $hasForeignCountry = preg_match('/^[A-Z]{2}$/', $countryCheck) && $countryCheck !== 'BR';
                if ($taxIdCheck === '' && !$hasForeignCountry) {
                    Database::update('notazz_documents', [
                        'status'     => 'manual',
                        'last_error' => 'Cliente sem CPF/CNPJ (provável estrangeiro). Abra a nota, defina o país (ISO-2) e clique Emitir.',
                    ], 'id = ?', [$doc['id']]);
                    $results['ignored']++;
                    Logger::warning('queue', "Doc #{$doc['id']} sem CPF e sem país — enviado para Revisão manual", null, $doc['asaas_payment_id'], (int)$doc['id']);
                    continue;
                }

                // Garante EXTERNAL_ID único e idempotente.
                // Se já temos um salvo, reusa (retries do mesmo doc).
                // Se não, gera novo com timestamp (evita colisão com docs anteriores na Notazz).
                if (empty($doc['notazz_external_id'])) {
                    $extId = sprintf(
                        'NOTAS-%d-%s-%d',
                        (int)$doc['id'],
                        strtoupper($doc['document_type']),
                        time()
                    );
                    Database::update('notazz_documents', ['notazz_external_id' => $extId], 'id = ?', [$doc['id']]);
                    $doc['notazz_external_id'] = $extId;
                }

                // Empresa já resolvida acima (define API key Notazz, split, agenda e fiscal).
                if ($companyId > 1 && ($company === null || empty($company['notazz_api_key']))) {
                    // Empresa sem API key cadastrada: não tenta (falharia na conta errada!).
                    DocumentRepo::markError(
                        (int)$doc['id'],
                        "Empresa #{$companyId} sem API key do Notazz cadastrada (painel > Empresas).",
                        null, (int)$doc['attempts'], $retryBackoff, $maxAttempts
                    );
                    $results['errors']++;
                    continue;
                }
                $client = $this->clientFor($company);

                $emitter = $doc['document_type'] === 'nfe'
                    ? new NFeEmitter($client)
                    : new NFSeEmitter($client);

                // Valor desta nota após o split (para exibir no painel)
                $docValue = ValueSplitter::forDocument($doc, $payment, $company);

                $params = $emitter->buildParams($doc, $payment, $product, $company);
                Database::update('notazz_documents', [
                    'document_value' => $docValue,
                    'payload_sent'   => json_encode(Security::scrubSecrets($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], 'id = ?', [$doc['id']]);

                $outcome = $emitter->emit($doc, $payment, $product, $company);
                $payloadResponse = json_encode($outcome['raw_response'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($outcome['success']) {
                    DocumentRepo::markSent((int)$doc['id'], [
                        'notazz_external_id' => $outcome['external_id'],
                        'notazz_document_id' => $outcome['notazz_id'],
                        'notazz_number'      => $outcome['numero'],
                        'notazz_key'         => $outcome['chave'],
                        'pdf_url'            => $outcome['pdf_url'],
                        'xml_url'            => $outcome['xml_url'],
                        'payload_response'   => $payloadResponse,
                    ]);
                    $results['sent']++;
                    Logger::info('queue', "Documento {$doc['document_type']} #{$doc['id']} emitido", [
                        'numero' => $outcome['numero'],
                    ], $doc['asaas_payment_id'], (int)$doc['id']);
                } else {
                    $errMsg = $outcome['message'] ?? 'Resposta sem sucesso (' . $outcome['http_status'] . ')';
                    DocumentRepo::markError(
                        (int)$doc['id'],
                        $errMsg,
                        $payloadResponse,
                        (int)$doc['attempts'],
                        $retryBackoff,
                        $maxAttempts
                    );
                    $results['errors']++;
                    Logger::error('queue', "Documento {$doc['document_type']} #{$doc['id']} falhou: {$errMsg}", null, $doc['asaas_payment_id'], (int)$doc['id']);
                }

                // Respeita o rate limit da Notazz entre uma emissão e a próxima.
                if ($paceUs) { usleep($paceUs); }
            } catch (\Throwable $e) {
                DocumentRepo::markError(
                    (int)$doc['id'],
                    $e->getMessage(),
                    null,
                    (int)$doc['attempts'],
                    $retryBackoff,
                    $maxAttempts
                );
                $results['errors']++;
                Logger::error('queue', 'Exceção: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()], $doc['asaas_payment_id'], (int)$doc['id']);
            }
        }

        return $results;
    }

    /**
     * Para documentos já emitidos mas que ainda não têm número/PDF/XML
     * (SEFAZ/Prefeitura demoram alguns segundos a minutos para processar),
     * consulta a Notazz e atualiza com os dados finais.
     */
    public function checkPendingStatus(int $limit = 20): array
    {
        $docs = Database::all(
            "SELECT * FROM notazz_documents
             WHERE status = 'sent'
               AND notazz_document_id IS NOT NULL AND notazz_document_id <> ''
               AND (notazz_number IS NULL OR notazz_number = ''
                    OR pdf_url IS NULL OR pdf_url = ''
                    OR xml_url IS NULL OR xml_url = '')
               AND sent_at >= NOW() - INTERVAL 24 HOUR
             ORDER BY id DESC
             LIMIT " . (int)$limit
        );

        $checked = count($docs);
        $updated = 0;
        $paceUs  = max(0, (int)Bootstrap::config('queue.pace_ms', 800)) * 1000;

        foreach ($docs as $doc) {
            try {
                // Consulta com a API key da empresa do documento (multi-empresa)
                $company = CompanyRepo::find((int)($doc['company_id'] ?? 1)) ?: null;
                $client  = $this->clientFor($company);

                $result = $doc['document_type'] === 'nfe'
                    ? $client->consultNfe((string)$doc['notazz_document_id'])
                    : $client->consultNfse((string)$doc['notazz_document_id']);

                $resp = $result['response'] ?? [];
                $num = $resp['numero'] ?? $resp['nNF'] ?? $resp['nNFSe'] ?? $resp['number'] ?? null;
                $key = $resp['chave'] ?? $resp['chave_nfe'] ?? $resp['codigo_verificacao'] ?? $resp['key'] ?? null;
                $pdf = $resp['pdf'] ?? $resp['url_pdf'] ?? $resp['link_pdf'] ?? $resp['pdfUrl'] ?? null;
                $xml = $resp['xml'] ?? $resp['url_xml'] ?? $resp['link_xml'] ?? $resp['xmlUrl'] ?? null;

                $update = [];
                if ($num && empty($doc['notazz_number'])) $update['notazz_number'] = (string)$num;
                if ($key && empty($doc['notazz_key']))    $update['notazz_key']    = (string)$key;
                if ($pdf && empty($doc['pdf_url']))       $update['pdf_url']       = (string)$pdf;
                if ($xml && empty($doc['xml_url']))       $update['xml_url']       = (string)$xml;

                // SEMPRE salva a resposta crua no payload_response para auditoria
                Database::update('notazz_documents', [
                    'payload_response' => json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], 'id = ?', [$doc['id']]);

                if ($update) {
                    Database::update('notazz_documents', $update, 'id = ?', [$doc['id']]);
                    $updated++;
                    Logger::info('status_check', "Doc #{$doc['id']} enriquecido", ['fields' => array_keys($update)], $doc['asaas_payment_id'], (int)$doc['id']);
                } else {
                    Logger::info('status_check', "Doc #{$doc['id']} consulta sem novos dados", [
                        'response_keys' => array_keys($resp),
                        'extracted' => compact('num', 'key', 'pdf', 'xml'),
                    ], $doc['asaas_payment_id'], (int)$doc['id']);
                }
            } catch (\Throwable $e) {
                Logger::warning('status_check', "Falha consulta #{$doc['id']}: " . $e->getMessage(), null, $doc['asaas_payment_id'], (int)$doc['id']);
            }
            if ($paceUs) { usleep($paceUs); }
        }

        return ['checked' => $checked, 'updated' => $updated];
    }
}
