<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Database;

final class Logger
{
    public static function info(string $source, string $message, ?array $context = null, ?string $paymentId = null, ?int $documentId = null): void
    {
        self::log('info', $source, $message, $context, $paymentId, $documentId);
    }

    public static function warning(string $source, string $message, ?array $context = null, ?string $paymentId = null, ?int $documentId = null): void
    {
        self::log('warning', $source, $message, $context, $paymentId, $documentId);
    }

    public static function error(string $source, string $message, ?array $context = null, ?string $paymentId = null, ?int $documentId = null): void
    {
        self::log('error', $source, $message, $context, $paymentId, $documentId);
    }

    public static function debug(string $source, string $message, ?array $context = null, ?string $paymentId = null, ?int $documentId = null): void
    {
        self::log('debug', $source, $message, $context, $paymentId, $documentId);
    }

    private static function log(string $level, string $source, string $message, ?array $context, ?string $paymentId, ?int $documentId): void
    {
        $clean = $context === null ? null : Security::scrubSecrets($context);
        try {
            Database::insert('processing_logs', [
                'level'             => $level,
                'source'            => substr($source, 0, 40),
                'document_id'       => $documentId,
                'asaas_payment_id'  => $paymentId,
                'message'           => $message,
                'context'           => $clean === null ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $e) {
            error_log('[Logger] falha persistir: ' . $e->getMessage());
        }
    }
}
