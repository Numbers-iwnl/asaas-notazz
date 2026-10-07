<?php
declare(strict_types=1);

namespace App\Clients;

use App\Bootstrap;
use RuntimeException;

/**
 * Cliente HTTP da API Notazz. A API aceita POST application/json
 * para o endpoint base; o campo "METHOD" determina a operação.
 */
final class NotazzClient
{
    private string $base;
    private string $apiKey;
    private int $timeout;

    /**
     * @param string|null $apiKeyOverride Chave da empresa (multi-empresa).
     *                    NULL = usa a chave padrão do config.php (Empresa 1 (Educação)).
     */
    public function __construct(?string $apiKeyOverride = null)
    {
        $this->base    = rtrim((string)Bootstrap::config('notazz.api_base'), '/');
        $this->apiKey  = $apiKeyOverride !== null && $apiKeyOverride !== ''
            ? $apiKeyOverride
            : (string)Bootstrap::config('notazz.api_key');
        $this->timeout = (int)Bootstrap::config('notazz.timeout', 30);
    }

    public function call(string $method, array $params): array
    {
        $payload = array_merge([
            'API_KEY' => $this->apiKey,
            'METHOD'  => $method,
        ], $params);

        $ch = curl_init($this->base);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT      => 'AsaasNotazz/1.0',
        ]);

        $raw   = curl_exec($ch);
        $stat  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha cURL Notazz: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Notazz retornou resposta não-JSON ({$stat}): " . substr((string)$raw, 0, 500));
        }

        return [
            'http_status' => $stat,
            'response'    => $decoded,
            'raw_request' => $payload,
        ];
    }

    public function createNfe(array $params): array
    {
        return $this->call('create_nfe_55', $params);
    }

    public function createNfse(array $params): array
    {
        return $this->call('create_nfse', $params);
    }

    public function consultNfe(string $documentId): array
    {
        return $this->call('consult_nfe_55', ['DOCUMENT_ID' => $documentId]);
    }

    public function consultNfse(string $documentId): array
    {
        return $this->call('consult_nfse', ['DOCUMENT_ID' => $documentId]);
    }
}
