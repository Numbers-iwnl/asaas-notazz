<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class ProductRepo
{
    public static function all(): array
    {
        return Database::all('SELECT * FROM products ORDER BY ignored ASC, active DESC, name ASC');
    }

    public static function active(): array
    {
        return Database::all('SELECT * FROM products WHERE active = 1 AND ignored = 0 ORDER BY id ASC');
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public static function save(int $id, array $data): void
    {
        $allowed = ['company_id','name','nfe_name','asaas_description','keywords','notazz_product_id','notazz_service_id','fiscal_group','active','ignored','notes'];
        $filtered = array_intersect_key($data, array_flip($allowed));
        Database::update('products', $filtered, 'id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        $allowed = ['company_id','name','nfe_name','asaas_description','keywords','notazz_product_id','notazz_service_id','fiscal_group','active','ignored','notes'];
        return Database::insert('products', array_intersect_key($data, array_flip($allowed)));
    }

    public static function nameExists(string $name): bool
    {
        return Database::one('SELECT 1 FROM products WHERE name = ? LIMIT 1', [$name]) !== null;
    }
}
