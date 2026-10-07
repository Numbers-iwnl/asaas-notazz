<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Bootstrap;
use DateTime;

/**
 * Calcula a data de emissão programada (DOCUMENT_ISSUE_DATE) enviada à Notazz.
 *
 * Regras:
 *  - Cartão de crédito PARCELADO: agenda para o VENCIMENTO da parcela (due_date),
 *    pois o Asaas confirma todas as parcelas no dia da compra, mas cada uma tem
 *    seu vencimento mensal. Assim sai 1 nota por parcela, no mês de vencimento.
 *    Se o vencimento já passou (parcela atrasada/legado pontual), emite com o
 *    atraso padrão a partir de hoje.
 *  - Demais casos (à vista, PIX, boleto): hoje + auto_emission_delay_days,
 *    dando margem ao financeiro antes do envio automático.
 */
final class IssueSchedule
{
    public static function compute(array $payment = [], ?array $company = null): string
    {
        $delay = $company !== null && isset($company['auto_emission_delay_days'])
            ? max(0, (int)$company['auto_emission_delay_days'])
            : max(0, (int)Bootstrap::config('emitter.auto_emission_delay_days', 0));
        $today = new DateTime('today');

        $billing       = (string)($payment['billing_type'] ?? '');
        $installmentNo = $payment['installment_number'] ?? null;
        $isCardInstall = ($billing === 'CREDIT_CARD')
            && $installmentNo !== null && $installmentNo !== '';

        if ($isCardInstall && !empty($payment['due_date'])) {
            try {
                $due = new DateTime(substr((string)$payment['due_date'], 0, 10));
                if ($due >= $today) {
                    // Agenda para o vencimento da parcela (emite no mês de vencimento).
                    return $due->format('Y-m-d') . ' 09:00:00';
                }
            } catch (\Throwable $e) {
                // due_date inválido: cai no comportamento padrão abaixo.
            }
            // Vencimento já passou: emite hoje + atraso padrão.
        }

        $dt = new DateTime('now');
        if ($delay > 0) {
            $dt->modify("+{$delay} days");
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
