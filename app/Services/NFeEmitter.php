<?php
declare(strict_types=1);

namespace App\Services;

use App\Bootstrap;
use App\Clients\NotazzClient;
use App\Helpers\AddressFallback;
use App\Helpers\FiscalConfig;
use App\Helpers\IssueSchedule;
use App\Helpers\Security;
use App\Helpers\TomadorValidator;
use App\Helpers\ValueSplitter;
use RuntimeException;

/**
 * Monta o payload e emite NF-e (modelo 55) na Notazz.
 * O produto é identificado pelo PRODUCT_ID já cadastrado na Notazz
 * (planilha "Produtos Asaas"); a tributação fica do lado da Notazz.
 */
final class NFeEmitter
{
    public function __construct(private NotazzClient $client) {}

    public function emit(array $document, array $payment, array $product, ?array $company = null): array
    {
        $params = $this->buildParams($document, $payment, $product, $company);
        $result = $this->client->createNfe($params);
        return $this->interpret($result, $params);
    }

    public function buildParams(array $document, array $payment, array $product, ?array $company = null): array
    {
        $personType = $payment['customer_person_type'] ?? null;
        $taxId   = Security::onlyDigits((string)($payment['customer_cpfcnpj'] ?? ''));
        $country = strtoupper(trim((string)($payment['customer_country'] ?? '')));

        // Estrangeiro: sem CPF/CNPJ, mas com país ISO-2 definido (≠ BR).
        $isForeign = ($taxId === '' && preg_match('/^[A-Z]{2}$/', $country) && $country !== 'BR');
        if (!$taxId && !$isForeign) {
            throw new RuntimeException('CPF/CNPJ ausente no pagamento. Se for estrangeiro, defina o país na tela da nota (Revisão).');
        }

        // Notazz espera LETRA no tipo de pessoa: F=Física, J=Jurídica, E=Estrangeiro (não número).
        $taxType = $isForeign ? 'E' : (($personType === 'J' || strlen($taxId) === 14) ? 'J' : 'F');

        // Trava: bloqueia emissão com tomador inconsistente (ver NFSeEmitter).
        TomadorValidator::assertValido($taxType, $taxId, $country, (string)($payment['customer_name'] ?? ''));

        $value   = number_format(ValueSplitter::forDocument($document, $payment, $company), 2, '.', '');
        $addr    = AddressFallback::resolve($payment, $company);
        $emitterUf = (string)($company['addr_state'] ?? Bootstrap::config('emitter.state', 'RN'));

        $params = [
            'EXTERNAL_ID'           => $document['notazz_external_id'] ?? ('ASAAS-' . $payment['asaas_payment_id'] . '-NFE-' . time()),
            'DESTINATION_TAXID'     => $isForeign ? Security::onlyDigits((string)($payment['customer_foreign_doc'] ?? '')) : $taxId,
            'DESTINATION_TAXTYPE'   => $taxType,
            'DESTINATION_NAME'      => mb_substr((string)($payment['customer_name'] ?? ''), 0, 120),
            'DESTINATION_EMAIL'     => (string)($payment['customer_email'] ?? ''),
            'DESTINATION_PHONE'     => Security::onlyDigits((string)($payment['customer_phone'] ?? '')),
            'DESTINATION_STREET'    => $addr['street'],
            'DESTINATION_NUMBER'    => $addr['number'],
            'DESTINATION_COMPLEMENT'=> $addr['complement'],
            'DESTINATION_DISTRICT'  => $addr['district'],
            'DESTINATION_CITY'      => $addr['city'],
            'DESTINATION_UF'        => $addr['state'],
            'DESTINATION_ZIPCODE'   => $addr['zipcode'],
            'DOCUMENT_BASEVALUE'    => $value,
            'DOCUMENT_VALUE'        => $value,   // Notazz NF-e exige o total da nota
            'DOCUMENT_ISSUE_DATE'   => IssueSchedule::compute($payment, $company),  // agenda (cartão parcelado = vencimento da parcela)
            'DOCUMENT_DESCRIPTION'  => mb_substr((string)($payment['description'] ?? $product['name']), 0, 250),
            'DOCUMENT_PRODUCT'      => [[
                'DOCUMENT_PRODUCT_COD'           => (string)$product['notazz_product_id'],
                'DOCUMENT_PRODUCT_NAME'          => mb_substr((string)($product['nfe_name'] ?? $product['name'] ?? 'Produto'), 0, 120),
                'DOCUMENT_PRODUCT_QTD'           => '1',
                'DOCUMENT_PRODUCT_UNITARY_VALUE' => $value,
                // ---- Tributação conforme planilha "Configuração NF de produto" ----
                'DOCUMENT_PRODUCT_NCM'              => FiscalConfig::NFE_PRODUCT['NCM'],
                // Estrangeiro usa CFOP de exportação (7102)
                'DOCUMENT_PRODUCT_CFOP'             => FiscalConfig::cfop($isForeign ? 'EX' : $addr['state'], $emitterUf),
                'DOCUMENT_PRODUCT_ICMS_CST'         => FiscalConfig::NFE_PRODUCT['ICMS_CST'],
                'DOCUMENT_PRODUCT_ICMS_ALIQUOTA'    => FiscalConfig::NFE_PRODUCT['ICMS_ALIQUOTA'],
                'DOCUMENT_PRODUCT_IPI_CST'          => FiscalConfig::NFE_PRODUCT['IPI_CST'],
                'DOCUMENT_PRODUCT_IPI_ALIQUOTA'     => FiscalConfig::NFE_PRODUCT['IPI_ALIQUOTA'],
                'DOCUMENT_PRODUCT_PIS_CST'          => FiscalConfig::NFE_PRODUCT['PIS_CST'],
                'DOCUMENT_PRODUCT_PIS_ALIQUOTA'     => FiscalConfig::NFE_PRODUCT['PIS_ALIQUOTA'],
                'DOCUMENT_PRODUCT_COFINS_CST'       => FiscalConfig::NFE_PRODUCT['COFINS_CST'],
                'DOCUMENT_PRODUCT_COFINS_ALIQUOTA'  => FiscalConfig::NFE_PRODUCT['COFINS_ALIQUOTA'],
                'DOCUMENT_PRODUCT_IBS_UF'           => FiscalConfig::NFE_PRODUCT['IBS_UF'],
                'DOCUMENT_PRODUCT_IBS_MUN'          => FiscalConfig::NFE_PRODUCT['IBS_MUN'],
                'DOCUMENT_PRODUCT_CBS'              => FiscalConfig::NFE_PRODUCT['CBS'],
                'DOCUMENT_PRODUCT_IS'               => FiscalConfig::NFE_PRODUCT['IS'],
                'DOCUMENT_PRODUCT_CCLASSTRIBIBSCBS' => FiscalConfig::NFE_PRODUCT['CCLASSTRIBIBSCBS'],
            ]],
        ];

        if ($isForeign) {
            $params['DESTINATION_COUNTRY'] = $country; // ISO 3166-1 alfa-2 (ex: PT, US, AR)
        }

        return $params;
    }

    private function interpret(array $result, array $params): array
    {
        $resp = $result['response'] ?? [];
        $http = $result['http_status'] ?? 0;

        // Notazz tem variações de chaves; tentamos extrair o que conseguir
        $status = $resp['statusProcessamento'] ?? $resp['status'] ?? null;
        $code   = $resp['codigoProcessamento'] ?? $resp['code'] ?? null;
        $msg    = $resp['motivo'] ?? $resp['message'] ?? $resp['mensagem'] ?? null;
        $docId  = $resp['id'] ?? $resp['document_id'] ?? null;
        $number = $resp['numero'] ?? $resp['nNF'] ?? null;
        $key    = $resp['chave'] ?? $resp['chave_nfe'] ?? null;
        $pdf    = $resp['pdf'] ?? $resp['url_pdf'] ?? $resp['link_pdf'] ?? null;
        $xml    = $resp['xml'] ?? $resp['url_xml'] ?? $resp['link_xml'] ?? null;

        $ok = $http >= 200 && $http < 300 && (
            in_array(strtolower((string)$status), ['ok','sucesso','success'], true)
            || (string)$code === '100'
            || (string)$code === '0'
            || !empty($docId)
        );
        if (!$ok && in_array(strtolower((string)$status), ['processing','processando','pendente'], true)) {
            // Notazz aceitou e está processando: mantém como sent (caso usuária queira diferenciar, painel mostra)
            $ok = true;
        }

        return [
            'success'      => $ok,
            'http_status'  => $http,
            'message'      => $msg,
            'notazz_id'    => $docId ? (string)$docId : null,
            'numero'       => $number ? (string)$number : null,
            'chave'        => $key ? (string)$key : null,
            'pdf_url'      => $pdf ? (string)$pdf : null,
            'xml_url'      => $xml ? (string)$xml : null,
            'external_id'  => $params['EXTERNAL_ID'],
            'raw_response' => $resp,
        ];
    }
}
