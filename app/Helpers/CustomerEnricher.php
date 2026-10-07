<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Clients\AsaasClient;
use App\Database;

/**
 * Enriquecimento de dados do cliente em duas etapas:
 *  1) Se o webhook trouxe `customer` só como ID, busca dados completos via API Asaas
 *  2) Se a cidade vier como código numérico (ID interno do Asaas) ou vazia,
 *     resolve nome real via ViaCEP usando o CEP do cliente.
 */
final class CustomerEnricher
{
    /** Cliente Asaas por token (multi-conta: cada empresa tem sua conta). */
    private static array $clients = [];

    public static function ensure(array $payment, ?string $asaasToken = null): array
    {
        $payment = self::enrichFromAsaas($payment, $asaasToken);
        $payment = self::resolveCityIfNumeric($payment);
        return $payment;
    }

    private static function enrichFromAsaas(array $payment, ?string $asaasToken = null): array
    {
        // Se já temos nome + cpf/cnpj, pula a chamada ao Asaas.
        if (!empty($payment['customer_name']) && !empty($payment['customer_cpfcnpj'])) {
            return $payment;
        }
        $customerId = $payment['asaas_customer_id'] ?? null;
        if (!$customerId) {
            return $payment;
        }

        try {
            $cacheKey = $asaasToken ?: '_default';
            self::$clients[$cacheKey] ??= new AsaasClient($asaasToken);
            $customer = self::$clients[$cacheKey]->getCustomer($customerId);
            if (!$customer) {
                Logger::warning('enrich', "Cliente Asaas {$customerId} não encontrado", null, $payment['asaas_payment_id'] ?? null);
                return $payment;
            }

            $cpf = Security::onlyDigits((string)($customer['cpfCnpj'] ?? ''));
            $personType = strlen($cpf) === 14 ? 'J' : (strlen($cpf) === 11 ? 'F' : null);

            $update = [
                'customer_name'           => $customer['name'] ?? null,
                'customer_cpfcnpj'        => $cpf ?: null,
                'customer_person_type'    => $personType,
                'customer_email'          => $customer['email'] ?? null,
                'customer_phone'          => $customer['phone'] ?? ($customer['mobilePhone'] ?? null),
                'customer_address'        => $customer['address'] ?? null,
                'customer_address_number' => $customer['addressNumber'] ?? null,
                'customer_complement'     => $customer['complement'] ?? null,
                'customer_province'       => $customer['province'] ?? null,
                'customer_postal_code'    => $customer['postalCode'] ?? null,
                'customer_city'           => $customer['city'] ?? null,
                'customer_state'          => $customer['state'] ?? null,
                // Asaas costuma trazer país por extenso ("Portugal"); só aproveita se vier ISO-2.
                'customer_country'        => (function() use ($customer) {
                    $c = strtoupper(trim((string)($customer['country'] ?? '')));
                    return (preg_match('/^[A-Z]{2}$/', $c) && $c !== 'BR') ? $c : null;
                })(),
            ];
            $update = array_filter($update, static fn($v) => $v !== null && $v !== '');

            if ($update) {
                Database::update('asaas_payments', $update, 'id = ?', [(int)$payment['id']]);
                $payment = array_merge($payment, $update);
                Logger::info('enrich', "Cliente {$customerId} enriquecido", ['fields' => array_keys($update)], $payment['asaas_payment_id'] ?? null);
            }
        } catch (\Throwable $e) {
            Logger::error('enrich', "Falha ao enriquecer cliente {$customerId}: " . $e->getMessage(), null, $payment['asaas_payment_id'] ?? null);
        }

        return $payment;
    }

    /**
     * Asaas às vezes retorna `city` como código numérico interno (ex: "7708").
     * Quando isso acontece, usamos o CEP para resolver o nome correto via ViaCEP.
     */
    private static function resolveCityIfNumeric(array $payment): array
    {
        $city = trim((string)($payment['customer_city'] ?? ''));
        $needsLookup = $city === '' || ctype_digit($city);
        if (!$needsLookup) {
            return $payment;
        }

        $cep = Security::onlyDigits((string)($payment['customer_postal_code'] ?? ''));
        if (strlen($cep) !== 8) {
            return $payment;
        }

        $viacep = self::viaCepLookup($cep);
        if (!$viacep) {
            Logger::warning('viacep', "Falha ao resolver CEP {$cep}", null, $payment['asaas_payment_id'] ?? null);
            return $payment;
        }

        $update = [];
        if (!empty($viacep['localidade']))       $update['customer_city']     = $viacep['localidade'];
        if (empty($payment['customer_state']) && !empty($viacep['uf']))      $update['customer_state'] = $viacep['uf'];
        if (empty($payment['customer_province']) && !empty($viacep['bairro'])) $update['customer_province'] = $viacep['bairro'];

        if ($update) {
            Database::update('asaas_payments', $update, 'id = ?', [(int)$payment['id']]);
            $payment = array_merge($payment, $update);
            Logger::info('viacep', "CEP {$cep} resolvido: {$viacep['localidade']}/{$viacep['uf']}", null, $payment['asaas_payment_id'] ?? null);
        }

        return $payment;
    }

    private static function viaCepLookup(string $cep): ?array
    {
        try {
            $ch = curl_init("https://viacep.com.br/ws/{$cep}/json/");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'AsaasNotazz/1.0',
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$resp, true);
            if (!is_array($data) || !empty($data['erro'])) {
                return null;
            }
            return $data;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
