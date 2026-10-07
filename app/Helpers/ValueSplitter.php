<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Database;

/**
 * Decide quanto do valor pago vai pra NF-e e quanto vai pra NFS-e
 * quando uma venda gera as duas notas.
 *
 * Regras:
 *   - Se a venda gera AMBAS as notas: split conforme config (default 50/50)
 *   - Se gera só uma das notas: ela leva o valor cheio
 *   - O complemento da NFS-e é calculado a partir do valor da NF-e
 *     para garantir que a soma seja EXATAMENTE igual ao valor pago
 *     (sem perder centavos por arredondamento)
 */
final class ValueSplitter
{
    public static function forDocument(array $doc, array $payment, ?array $company = null): float
    {
        $total = round((float)($payment['value'] ?? 0), 2);
        if ($total <= 0) {
            return 0.0;
        }

        $otherType = $doc['document_type'] === 'nfe' ? 'nfse' : 'nfe';
        $hasOther  = self::otherExists((int)$doc['payment_row_id'], $otherType);

        if (!$hasOther) {
            return $total;
        }

        // % da NF-e: da empresa (multi-empresa) ou do config (padrão Educação).
        $nfePercent = $company !== null && isset($company['nfe_percent'])
            ? (float)$company['nfe_percent']
            : (float)\App\Bootstrap::config('emitter.split.nfe_percent', 50);
        $nfePercent = max(0.0, min(100.0, $nfePercent));

        // Calcula NF-e arredondando pra baixo; NFS-e leva o complemento.
        // Isso garante soma exata mesmo com decimais imprecisos.
        $nfeValue = floor(($total * $nfePercent) ) / 100;
        $nfseValue = round($total - $nfeValue, 2);

        return $doc['document_type'] === 'nfe' ? $nfeValue : $nfseValue;
    }

    private static function otherExists(int $paymentRowId, string $type): bool
    {
        $row = Database::one(
            "SELECT id FROM notazz_documents
             WHERE payment_row_id = ? AND document_type = ?
               AND status NOT IN ('ignored')
             LIMIT 1",
            [$paymentRowId, $type]
        );
        return $row !== null;
    }
}
