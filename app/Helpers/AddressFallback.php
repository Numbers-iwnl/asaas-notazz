<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Bootstrap;

/**
 * Resolve endereço do destinatário usando os dados do payment
 * e caindo para o endereço default do emitente quando faltar info.
 */
final class AddressFallback
{
    public static function resolve(array $payment, ?array $company = null): array
    {
        // Fallback: endereço da empresa (multi-empresa) ou do config (padrão Educação).
        if ($company !== null && !empty($company['addr_city'])) {
            $default = [
                'street'     => $company['addr_street'] ?? 'nao informado',
                'number'     => $company['addr_number'] ?? 'SN',
                'complement' => $company['addr_complement'] ?? '',
                'district'   => $company['addr_district'] ?? 'nao informado',
                'city'       => $company['addr_city'],
                'state'      => $company['addr_state'] ?? '',
                'zipcode'    => $company['addr_zipcode'] ?? '',
            ];
        } else {
            $default = (array)Bootstrap::config('emitter.default_address', []);
        }

        $street  = self::pick($payment['customer_address'] ?? null, $default['street'] ?? 'nao informado');
        $number  = self::pick($payment['customer_address_number'] ?? null, $default['number'] ?? 'SN');
        // Endereço sem número (comum em Brasília) costuma vir cadastrado como "0";
        // a Notazz trata "0" como vazio e recusa a nota.
        if (preg_match('/^0+$/', trim((string)$number))) $number = 'SN';
        $compl   = self::pick($payment['customer_complement'] ?? null, $default['complement'] ?? '');
        $district = self::pick($payment['customer_province'] ?? null, $default['district'] ?? 'nao informado');
        $city    = self::pick($payment['customer_city'] ?? null, $default['city'] ?? '');
        $state   = self::pick($payment['customer_state'] ?? null, $default['state'] ?? '');
        $zip     = Security::onlyDigits((string)($payment['customer_postal_code'] ?? ''));

        if (strlen($zip) !== 8) {
            $zip = Security::onlyDigits((string)($default['zipcode'] ?? ''));
        }

        return [
            'street'     => $street,
            'number'     => $number,
            'complement' => $compl,
            'district'   => $district,
            'city'       => $city,
            'state'      => $state,
            'zipcode'    => $zip,
        ];
    }

    private static function pick($value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : $fallback;
    }
}
