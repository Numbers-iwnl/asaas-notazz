<?php
declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Trava de segurança do TOMADOR antes de emitir a nota.
 *
 * O bug do taxtype numérico (mandava 1/2 em vez de F/J) derrubava o tomador
 * do documento EM SILÊNCIO: a nota era emitida, mas saía "TOMADOR NÃO
 * IDENTIFICADO". Esta classe garante que isso não volte a acontecer sem
 * ninguém perceber — se o tipo de pessoa ou o documento estiverem
 * inconsistentes, a emissão PARA com erro claro (fica visível no painel)
 * em vez de gerar uma nota fiscal quebrada.
 *
 * Ver memória [[notazz-taxtype-letra]].
 */
final class TomadorValidator
{
    /** Valida (letra do tipo de pessoa + documento) antes de montar o payload. */
    public static function assertValido(string $taxType, string $taxId, string $country, string $nome): void
    {
        $quem = $nome !== '' ? " (tomador: {$nome})" : '';

        // 1) Tipo de pessoa TEM que ser letra F/J/E — nunca número (era o bug).
        if (!in_array($taxType, ['F', 'J', 'E'], true)) {
            throw new RuntimeException(
                "Tipo de pessoa do tomador inválido: \"{$taxType}\". Precisa ser F, J ou E (letra){$quem}."
            );
        }

        // 2) Estrangeiro: exige país ISO-2 (≠ BR). O documento é livre.
        if ($taxType === 'E') {
            if (!preg_match('/^[A-Z]{2}$/', $country) || $country === 'BR') {
                throw new RuntimeException(
                    "Tomador estrangeiro sem país válido (ISO-2, ex.: PT, US). Defina o país na tela de Revisão{$quem}."
                );
            }
            return;
        }

        // 3) Física: CPF de 11 dígitos, dígito verificador válido.
        if ($taxType === 'F') {
            if (strlen($taxId) !== 11) {
                throw new RuntimeException(
                    "CPF do tomador deve ter 11 dígitos; veio com " . strlen($taxId) . " (\"{$taxId}\"){$quem}."
                );
            }
            if (!self::cpfValido($taxId)) {
                throw new RuntimeException(
                    "CPF do tomador inválido (dígito verificador não confere): \"{$taxId}\"{$quem}. Corrija o cadastro no Asaas."
                );
            }
            return;
        }

        // 4) Jurídica: CNPJ de 14 dígitos, dígito verificador válido.
        if (strlen($taxId) !== 14) {
            throw new RuntimeException(
                "CNPJ do tomador deve ter 14 dígitos; veio com " . strlen($taxId) . " (\"{$taxId}\"){$quem}."
            );
        }
        if (!self::cnpjValido($taxId)) {
            throw new RuntimeException(
                "CNPJ do tomador inválido (dígito verificador não confere): \"{$taxId}\"{$quem}. Corrija o cadastro no Asaas."
            );
        }
    }

    public static function cpfValido(string $c): bool
    {
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int)$c[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int)$c[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function cnpjValido(string $c): bool
    {
        if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }
        $dv = static function (string $base, array $pesos): int {
            $soma = 0;
            foreach ($pesos as $i => $p) {
                $soma += (int)$base[$i] * $p;
            }
            $r = $soma % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        $d1 = $dv(substr($c, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
        $d2 = $dv(substr($c, 0, 13), [6,5,4,3,2,9,8,7,6,5,4,3,2]);
        return (int)$c[12] === $d1 && (int)$c[13] === $d2;
    }
}
