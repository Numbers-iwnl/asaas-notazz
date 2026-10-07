<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class LogRepo
{
    public static function listProcessing(int $limit = 200, ?string $level = null, ?string $from = null, ?string $to = null): array
    {
        [$dateSql, $dateBind] = \App\Helpers\DateFilter::clause('created_at', $from, $to);
        $where = '1=1';
        $bind = [];
        if ($level) { $where .= ' AND level = ?'; $bind[] = $level; }
        return Database::all(
            'SELECT * FROM processing_logs WHERE ' . $where . $dateSql . ' ORDER BY id DESC LIMIT ' . (int)$limit,
            array_merge($bind, $dateBind)
        );
    }

    public static function listWebhooks(int $limit = 200, ?string $from = null, ?string $to = null): array
    {
        [$dateSql, $dateBind] = \App\Helpers\DateFilter::clause('received_at', $from, $to);
        return Database::all(
            'SELECT id, received_at, remote_ip, event, asaas_payment_id, token_valid, json_valid, response_code
             FROM webhook_logs WHERE 1=1' . $dateSql . ' ORDER BY id DESC LIMIT ' . (int)$limit,
            $dateBind
        );
    }

    public static function findWebhook(int $id): ?array
    {
        return Database::one('SELECT * FROM webhook_logs WHERE id = ?', [$id]);
    }
}
