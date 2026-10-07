<?php
declare(strict_types=1);

namespace App\Clients;

use App\Bootstrap;
use RuntimeException;

final class AsaasClient
{
    private string $base;
    private string $token;

    /**
     * @param string|null $tokenOverride Token da conta Asaas da empresa (multi-conta).
     *                    NULL = usa o token padrão do config.php (Empresa 1 (Educação)).
     */
    public function __construct(?string $tokenOverride = null)
    {
        $this->base  = rtrim((string)Bootstrap::config('asaas.api_base'), '/');
        $this->token = $tokenOverride !== null && $tokenOverride !== ''
            ? $tokenOverride
            : (string)Bootstrap::config('asaas.api_token');
    }

    public function getCustomer(string $customerId): ?array
    {
        return $this->request('GET', "/customers/{$customerId}");
    }

    public function getPayment(string $paymentId): ?array
    {
        return $this->request('GET', "/payments/{$paymentId}");
    }

    private function request(string $method, string $path, ?array $body = null): ?array
    {
        $ch = curl_init($this->base . $path);
        $headers = [
            'access_token: ' . $this->token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'AsaasNotazz/1.0 (+github.com/Numbers-iwnl/asaas-notazz)',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER]  = $headers;
            $opts[CURLOPT_POSTFIELDS]  = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Falha cURL Asaas: ' . $err);
        }
        if ($status === 404) {
            return null;
        }
        if ($status >= 400) {
            throw new RuntimeException("Asaas {$method} {$path} retornou {$status}: " . substr((string)$resp, 0, 500));
        }
        $data = json_decode((string)$resp, true);
        return is_array($data) ? $data : null;
    }
}
