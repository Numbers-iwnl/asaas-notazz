<?php
declare(strict_types=1);

namespace App\Services;

use App\Clients\NotazzClient;
use App\Helpers\AddressFallback;
use App\Helpers\FiscalConfig;
use App\Helpers\IssueSchedule;
use App\Helpers\Security;
use App\Helpers\TomadorValidator;
use App\Helpers\ValueSplitter;
use RuntimeException;

/**
 * Emissor de NFS-e via Notazz.
 * O serviço é identificado pelo SERVICE_ID já cadastrado na Notazz.
 */
final class NFSeEmitter
{
    public function __construct(private NotazzClient $client) {}

    public function emit(array $document, array $payment, array $product, ?array $company = null): array
    {
        $params = $this->buildParams($document, $payment, $product, $company);
        $result = $this->client->createNfse($params);
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

        // Notazz espera LETRA no tipo de pessoa: F=Física, J=Jurídica, E=Estrangeiro
        // (mandar número derruba o CNPJ e a nota sai "tomador não identificado").
        $taxType   = $isForeign ? 'E' : (($personType === 'J' || strlen($taxId) === 14) ? 'J' : 'F');

        // Trava: se o tipo/documento estiver inconsistente, PARA com erro claro
        // em vez de emitir uma nota com o tomador derrubado em silêncio.
        TomadorValidator::assertValido($taxType, $taxId, $country, (string)($payment['customer_name'] ?? ''));

        $companyId = (int)($company['id'] ?? ($product['company_id'] ?? 1));
        $value     = number_format(ValueSplitter::forDocument($document, $payment, $company), 2, '.', '');
        $addr      = AddressFallback::resolve($payment, $company);
        $fiscal    = FiscalConfig::nfseGroup($product['fiscal_group'] ?? null, $companyId);

        $params = [
            'EXTERNAL_ID'          => $document['notazz_external_id'] ?? ('ASAAS-' . $payment['asaas_payment_id'] . '-NFSE-' . time()),
            'SERVICE_ID'           => (string)$product['notazz_service_id'],
            'DESTINATION_TAXID'    => $isForeign ? Security::onlyDigits((string)($payment['customer_foreign_doc'] ?? '')) : $taxId,
            'DESTINATION_TAXTYPE'  => $taxType,
            'DESTINATION_NAME'     => mb_substr((string)($payment['customer_name'] ?? ''), 0, 120),
            'DESTINATION_EMAIL'    => (string)($payment['customer_email'] ?? ''),
            'DESTINATION_PHONE'    => Security::onlyDigits((string)($payment['customer_phone'] ?? '')),
            'DESTINATION_STREET'   => $addr['street'],
            'DESTINATION_NUMBER'   => $addr['number'],
            'DESTINATION_COMPLEMENT'=> $addr['complement'],
            'DESTINATION_DISTRICT' => $addr['district'],
            'DESTINATION_CITY'     => $addr['city'],
            'DESTINATION_UF'       => $addr['state'],
            'DESTINATION_ZIPCODE'  => $addr['zipcode'],
            'DOCUMENT_BASEVALUE'   => $value,
            'DOCUMENT_ISSUE_DATE'  => IssueSchedule::compute($payment, $company),  // agenda (cartão parcelado = vencimento da parcela)
            'DOCUMENT_DESCRIPTION' => mb_substr((string)($payment['description'] ?? $product['name']), 0, 250),
            // ---- Tributação conforme planilha "Configuração NF de serviço" ----
            'DOCUMENT_CNAE'             => $fiscal['CNAE'],
            'NBS'                       => $fiscal['NBS'],
            'CITY_SERVICE_CODE'         => $fiscal['CITY_SERVICE_CODE'],
            'CITY_SERVICE_DESCRIPTION'  => $fiscal['CITY_SERVICE_DESCRIPTION'],
            'SERVICE_LIST_LC116'        => $fiscal['SERVICE_LIST_LC116'],
            'CCLASSTRIB'                => $fiscal['CCLASSTRIB'],
            'WITHHELD_ISS'              => $fiscal['WITHHELD_ISS'],
            'DOCUMENT_SERVICE_COD'      => (string)$product['notazz_service_id'],
            'DOCUMENT_SERVICE_NAME'     => mb_substr((string)$product['name'], 0, 120),
            'ALIQUOTAS'                 => $fiscal['ALIQUOTAS'],
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

        $status = $resp['statusProcessamento'] ?? $resp['status'] ?? null;
        $code   = $resp['codigoProcessamento'] ?? $resp['code'] ?? null;
        $msg    = $resp['motivo'] ?? $resp['message'] ?? $resp['mensagem'] ?? null;
        $docId  = $resp['id'] ?? $resp['document_id'] ?? null;
        $number = $resp['numero'] ?? $resp['nNFSe'] ?? null;
        $key    = $resp['chave'] ?? $resp['codigo_verificacao'] ?? null;
        $pdf    = $resp['pdf'] ?? $resp['url_pdf'] ?? $resp['link_pdf'] ?? null;
        $xml    = $resp['xml'] ?? $resp['url_xml'] ?? $resp['link_xml'] ?? null;

        $ok = $http >= 200 && $http < 300 && (
            in_array(strtolower((string)$status), ['ok','sucesso','success'], true)
            || (string)$code === '100'
            || (string)$code === '0'
            || !empty($docId)
        );
        if (!$ok && in_array(strtolower((string)$status), ['processing','processando','pendente'], true)) {
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
