<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class PaymentRepo
{
    public static function findByAsaasIdAndEvent(string $paymentId, string $event): ?array
    {
        return Database::one('SELECT * FROM asaas_payments WHERE asaas_payment_id = ? AND event = ? LIMIT 1', [$paymentId, $event]);
    }

    public static function create(array $data): int
    {
        return Database::insert('asaas_payments', $data);
    }

    public static function setMatchedProduct(int $id, ?int $productId): void
    {
        Database::update('asaas_payments', ['matched_product_id' => $productId], 'id = ?', [$id]);
    }

    public static function listRecent(int $limit = 100, ?string $search = null, ?string $from = null, ?string $to = null): array
    {
        [$dateSql, $dateBind] = \App\Helpers\DateFilter::clause('received_at', $from, $to);
        if ($search) {
            $needle = '%' . $search . '%';
            return Database::all(
                'SELECT * FROM asaas_payments
                 WHERE (description LIKE ? OR customer_name LIKE ? OR customer_cpfcnpj LIKE ? OR asaas_payment_id LIKE ?)' . $dateSql . '
                 ORDER BY id DESC LIMIT ' . (int)$limit,
                array_merge([$needle, $needle, $needle, $needle], $dateBind)
            );
        }
        return Database::all(
            'SELECT * FROM asaas_payments WHERE 1=1' . $dateSql . ' ORDER BY id DESC LIMIT ' . (int)$limit,
            $dateBind
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM asaas_payments WHERE id = ?', [$id]);
    }
}
