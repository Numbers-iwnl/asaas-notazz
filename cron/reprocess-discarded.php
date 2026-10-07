<?php
declare(strict_types=1);

/**
 * Reprocessa webhooks que foram DESCARTADOS por "wrong_event_for_billing_type"
 * (cartões que o sistema ignorou indevidamente) e que NUNCA viraram venda.
 *
 * Como funciona: relê o raw_body salvo em webhook_logs e faz POST de volta para o
 * próprio endpoint /webhook-asaas.php, com o token. Assim o processamento usa
 * exatamente a lógica ATUAL do webhook (cartão parcelado aceito + agendado por
 * vencimento + trava anti-legado). É idempotente: o dedup impede duplicar vendas.
 *
 * Uso (SSH):
 *   /opt/alt/php82/usr/bin/php cron/reprocess-discarded.php [dias]
 *   - dias (opcional): só reprocessa webhooks recebidos nos últimos N dias. Padrão: 20.
 *
 * IMPORTANTE: rode somente DEPOIS de subir o webhook-asaas.php corrigido.
 */

require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Database;
use App\Bootstrap;

$dias  = isset($argv[1]) ? max(1, (int)$argv[1]) : 20;
$token = (string)Bootstrap::config('asaas.webhook_token');
$url   = rtrim((string)Bootstrap::config('app.base_url'), '/') . '/webhook-asaas.php';

$rows = Database::all(
    "SELECT w.id, w.raw_body, w.asaas_payment_id, w.received_at
     FROM webhook_logs w
     WHERE w.response_body LIKE '%wrong_event_for_billing_type%'
       AND w.raw_body LIKE '%CREDIT_CARD%'
       AND w.received_at >= NOW() - INTERVAL {$dias} DAY
       AND NOT EXISTS (
           SELECT 1 FROM asaas_payments p WHERE p.asaas_payment_id = w.asaas_payment_id
       )
     ORDER BY w.id ASC"
);

echo "Reprocessando " . count($rows) . " webhook(s) descartado(s) dos últimos {$dias} dias...\n";
$enq = 0; $manual = 0; $other = 0; $fail = 0;

foreach ($rows as $r) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $r['raw_body'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'asaas-access-token: ' . $token,
        ],
    ]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code === 0) {
        $fail++;
        $tag = 'FALHA_CONEXAO';
    } elseif (strpos($resp, '"enqueued":[{') !== false && strpos($resp, '"status":"pending"') !== false) {
        $enq++;
        $tag = 'AGENDADO';
    } elseif (strpos($resp, '"status":"manual"') !== false || strpos($resp, 'legacy_manual_review') !== false) {
        $manual++;
        $tag = 'REVISAO_MANUAL(legado)';
    } else {
        $other++;
        $tag = 'OUTRO';
    }
    echo sprintf("  #%d %s [%s] HTTP %d %s\n", $r['id'], $r['asaas_payment_id'], $tag, $code, substr($resp, 0, 90));
    usleep(150000); // 0,15s entre chamadas para não sobrecarregar
}

echo "\nResumo: agendados={$enq} | revisao_manual={$manual} | outros={$other} | falhas={$fail}\n";
echo "Confira no painel: Notas pendentes (agendadas) e Revisão (legado).\n";
