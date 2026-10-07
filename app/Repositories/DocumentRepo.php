<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class DocumentRepo
{
    public static function exists(string $asaasPaymentId, string $type): bool
    {
        $row = Database::one('SELECT id FROM notazz_documents WHERE asaas_payment_id = ? AND document_type = ? LIMIT 1', [$asaasPaymentId, $type]);
        return $row !== null;
    }

    public static function createPending(string $asaasPaymentId, int $paymentRowId, ?int $productId, string $type, string $status = 'pending', int $companyId = 1): int
    {
        return Database::insert('notazz_documents', [
            'asaas_payment_id' => $asaasPaymentId,
            'payment_row_id'   => $paymentRowId,
            'product_id'       => $productId,
            'document_type'    => $type,
            'status'           => $status,
            'company_id'       => $companyId,
        ]);
    }

    public static function fetchAndLockNextBatch(int $limit): array
    {
        // Lock simples: marca como "processing" e usa locked_at.
        // Em ambiente shared hosting (sem worker concorrente) é suficiente.
        Database::pdo()->beginTransaction();
        try {
            $rows = Database::all(
                'SELECT * FROM notazz_documents
                 WHERE status = ? AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                 ORDER BY id ASC LIMIT ' . (int)$limit . ' FOR UPDATE',
                ['pending']
            );
            if (!$rows) {
                Database::pdo()->commit();
                return [];
            }
            $ids = array_column($rows, 'id');
            $place = implode(',', array_fill(0, count($ids), '?'));
            Database::run("UPDATE notazz_documents SET status='processing', locked_at=NOW(), attempts=attempts+1 WHERE id IN ({$place})", $ids);
            Database::pdo()->commit();
            return Database::all("SELECT * FROM notazz_documents WHERE id IN ({$place}) ORDER BY id ASC", $ids);
        } catch (\Throwable $e) {
            Database::pdo()->rollBack();
            throw $e;
        }
    }

    public static function markSent(int $id, array $fields): void
    {
        $fields['status']  = 'sent';
        $fields['sent_at'] = date('Y-m-d H:i:s');
        $fields['last_error'] = null;
        Database::update('notazz_documents', $fields, 'id = ?', [$id]);
    }

    public static function markError(int $id, string $error, ?string $payloadResponse, int $attempts, array $retryBackoff, int $maxAttempts): void
    {
        $status = $attempts >= $maxAttempts ? 'error' : 'pending';
        $delay  = $retryBackoff[min($attempts - 1, count($retryBackoff) - 1)] ?? 600;
        $next   = date('Y-m-d H:i:s', time() + $delay);
        Database::update('notazz_documents', [
            'status'           => $status,
            'last_error'       => mb_substr($error, 0, 65000),
            'payload_response' => $payloadResponse,
            'next_retry_at'    => $status === 'pending' ? $next : null,
        ], 'id = ?', [$id]);
    }

    public static function markIgnored(int $id, string $reason): void
    {
        Database::update('notazz_documents', [
            'status'     => 'ignored',
            'last_error' => $reason,
        ], 'id = ?', [$id]);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM notazz_documents WHERE id = ?', [$id]);
    }

    public static function listByStatus(string $status, int $limit = 200, ?string $from = null, ?string $to = null, ?int $companyId = null): array
    {
        [$dateSql, $dateBind] = \App\Helpers\DateFilter::clause('d.created_at', $from, $to);
        $companySql = $companyId ? ' AND d.company_id = ' . (int)$companyId : '';
        return Database::all('SELECT d.*, p.customer_name, p.customer_cpfcnpj, p.value AS sale_value, p.description
            FROM notazz_documents d
            JOIN asaas_payments p ON p.id = d.payment_row_id
            WHERE d.status = ?' . $dateSql . $companySql . '
            ORDER BY d.id DESC LIMIT ' . (int)$limit, array_merge([$status], $dateBind));
    }

    public static function listAll(int $limit = 200): array
    {
        return Database::all('SELECT d.*, p.customer_name, p.customer_cpfcnpj, p.value AS sale_value, p.description
            FROM notazz_documents d
            JOIN asaas_payments p ON p.id = d.payment_row_id
            ORDER BY d.id DESC LIMIT ' . (int)$limit);
    }

    public static function findFull(int $id): ?array
    {
        return Database::one('SELECT d.*, p.customer_name, p.customer_cpfcnpj, p.value AS sale_value, p.description, p.raw_payload, p.event, p.billing_type, p.customer_email, p.customer_country, p.customer_foreign_doc
            FROM notazz_documents d
            JOIN asaas_payments p ON p.id = d.payment_row_id
            WHERE d.id = ?', [$id]);
    }

    public static function reset(int $id): void
    {
        Database::update('notazz_documents', [
            'status'        => 'pending',
            'locked_at'     => null,
            'next_retry_at' => null,
        ], 'id = ?', [$id]);
    }

    public static function countByStatus(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) c FROM notazz_documents GROUP BY status');
        $out = ['pending'=>0,'processing'=>0,'sent'=>0,'error'=>0,'ignored'=>0,'manual'=>0];
        foreach ($rows as $r) $out[$r['status']] = (int)$r['c'];
        return $out;
    }

    /**
     * Estatísticas com contagem + soma de valores por status, para o dashboard.
     */
    public static function statsByStatus(?string $from = null, ?string $to = null, ?int $companyId = null): array
    {
        [$dateSql, $dateBind] = \App\Helpers\DateFilter::clause('created_at', $from, $to);
        $companySql = $companyId ? ' AND company_id = ' . (int)$companyId : '';
        $rows = Database::all(
            'SELECT status, COUNT(*) c, COALESCE(SUM(document_value),0) v
             FROM notazz_documents WHERE 1=1' . $dateSql . $companySql . ' GROUP BY status',
            $dateBind
        );
        $base = ['pending','processing','sent','error','ignored','manual'];
        $out = [];
        foreach ($base as $s) $out[$s] = ['count' => 0, 'value' => 0.0];
        foreach ($rows as $r) {
            $out[$r['status']] = ['count' => (int)$r['c'], 'value' => (float)$r['v']];
        }
        return $out;
    }

    /**
     * Total emitido (R$) por mês nos últimos N meses, para o gráfico.
     */
    public static function monthlyEmitted(int $months = 6, ?int $companyId = null): array
    {
        $companySql = $companyId ? ' AND company_id = ' . (int)$companyId : '';
        $rows = Database::all(
            "SELECT DATE_FORMAT(sent_at, '%Y-%m') ym, COALESCE(SUM(document_value),0) v, COUNT(*) c
             FROM notazz_documents
             WHERE status = 'sent' AND sent_at IS NOT NULL" . $companySql . "
             GROUP BY ym ORDER BY ym ASC"
        );
        // Mantém só os últimos N
        return array_slice($rows, -$months);
    }
}
