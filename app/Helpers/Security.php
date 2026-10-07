<?php
declare(strict_types=1);

namespace App\Helpers;

final class Security
{
    public const SENSITIVE_KEYS = [
        'api_key', 'apikey', 'api_token', 'token', 'access_token', 'access-token',
        'asaas-access-token', 'password', 'authorization', 'webhook_token',
        'API_KEY', 'AUTHORIZATION',
    ];

    /**
     * Recursivamente substitui valores de chaves sensíveis por *** antes de logar.
     */
    public static function scrubSecrets(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (stripos((string)$k, $needle) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $out[$k] = is_string($v) && $v !== '' ? '***REDACTED***' : $v;
                continue;
            }
            $out[$k] = is_array($v) ? self::scrubSecrets($v) : $v;
        }
        return $out;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfCheck(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function onlyDigits(?string $s): string
    {
        return preg_replace('/\D+/', '', (string)$s) ?? '';
    }
}
