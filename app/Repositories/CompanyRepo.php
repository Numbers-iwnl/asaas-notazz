<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Database;

final class CompanyRepo
{
    /** Cache por request (o cron consulta a mesma empresa várias vezes). */
    private static array $cache = [];

    public static function find(int $id): ?array
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $row = Database::one('SELECT * FROM companies WHERE id = ?', [$id]);
        if ($row) {
            self::$cache[$id] = $row;
        }
        return $row;
    }

    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM companies' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY id ASC';
        return Database::all($sql);
    }

    public static function save(int $id, array $data): void
    {
        $allowed = [
            'name','trade_name','cnpj','municipal_reg','state_reg','tax_regime',
            'notazz_api_key','asaas_api_token','addr_street','addr_number','addr_complement','addr_district',
            'addr_city','addr_state','addr_zipcode','city_ibge',
            'nfe_percent','auto_emission_delay_days','active',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));
        if ($filtered) {
            Database::update('companies', $filtered, 'id = ?', [$id]);
            unset(self::$cache[$id]);
        }
    }
}
