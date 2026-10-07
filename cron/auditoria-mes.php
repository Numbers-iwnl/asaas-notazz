<?php
declare(strict_types=1);

/**
 * AUDITORIA DO MÊS — cruza as vendas do Asaas (vencimento no mês) com o nosso
 * banco e classifica cada uma. Mostra, por EMPRESA, o que já está emitido e o
 * que falta. Read-only por padrão; com --enviar, reinjeta o que nunca chegou.
 *
 * Uso (SSH):
 *   php cron/auditoria-mes.php [YYYY-MM] [--enviar]
 *     YYYY-MM  : mês a auditar (padrão: mês atual). Filtra por dueDate.
 *     --enviar : além do relatório, reinjeta no webhook as vendas "NÃO IMPORTADAS"
 *                (as que nunca viraram registro). Idempotente.
 *
 * Categorias:
 *   JÁ EMITIDA   - tem nota com status 'sent'
 *   NA FILA      - pending/processing/manual (cron emite / revisão)
 *   ERRO         - status 'error'
 *   SEM PRODUTO  - descrição não casou com nenhum produto (cadastrar/ajustar keyword)
 *   NÃO IMPORTADA- nunca virou registro (candidata a enviar)
 */

require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Bootstrap;
use App\Database;
use App\Services\ProductMatcher;
use App\Repositories\ProductRepo;
use App\Repositories\CompanyRepo;

$mes    = isset($argv[1]) && preg_match('/^\d{4}-\d{2}$/', (string)$argv[1]) ? $argv[1] : date('Y-m');
$enviar = in_array('--enviar', $argv, true);
$ini    = $mes . '-01';
$fim    = date('Y-m-t', strtotime($ini));

$asaasToken = (string)Bootstrap::config('asaas.api_token');
$asaasBase  = rtrim((string)Bootstrap::config('asaas.api_base'), '/');
$hookToken  = (string)Bootstrap::config('asaas.webhook_token');
$hookUrl    = rtrim((string)Bootstrap::config('app.base_url'), '/') . '/webhook-asaas.php';

function asaasGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT      => 'AsaasNotazz/1.0 (+github.com/Numbers-iwnl/asaas-notazz)', // Asaas exige User-Agent
        CURLOPT_HTTPHEADER => ['access_token: ' . $token, 'Accept: application/json'],
    ]);
    $raw = (string)curl_exec($ch); curl_close($ch);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// Nome das empresas para o relatório
$empNome = [0 => 'SEM EMPRESA (produto não casou)'];
foreach (CompanyRepo::all() as $c) { $empNome[(int)$c['id']] = $c['trade_name'] ?: $c['name']; }

echo "==================================================================\n";
echo " AUDITORIA {$mes}  (vencimento {$ini} a {$fim})" . ($enviar ? "  [MODO ENVIO]" : "  [somente relatório]") . "\n";
echo "==================================================================\n";

$stats = [];  // [empresaId][categoria] = count
$faltam = []; // lista de NÃO IMPORTADA para enviar
$semProduto = [];
$offset = 0; $limit = 100; $total = 0;

do {
    $url = $asaasBase . "/payments?dueDate%5Bge%5D={$ini}&dueDate%5Ble%5D={$fim}&limit={$limit}&offset={$offset}";
    $page = asaasGet($url, $asaasToken);
    $data = $page['data'] ?? [];
    $hasMore = (bool)($page['hasMore'] ?? false);

    foreach ($data as $p) {
        $status = (string)($p['status'] ?? '');
        if (!in_array($status, ['CONFIRMED', 'RECEIVED'], true)) continue;
        $total++;

        $desc = (string)($p['description'] ?? '');
        $matched = ProductMatcher::match($desc);
        $companyId = $matched ? (int)($matched['company_id'] ?? 1) : 0;

        // Estado no nosso banco
        $docs = Database::all(
            'SELECT status FROM notazz_documents WHERE asaas_payment_id = ?',
            [(string)$p['id']]
        );
        $existsPay = (bool)Database::one('SELECT 1 FROM asaas_payments WHERE asaas_payment_id = ? LIMIT 1', [(string)$p['id']]);
        $st = array_column($docs, 'status');

        if (!$matched) {
            $cat = 'SEM PRODUTO';
            $semProduto[$desc] = ($semProduto[$desc] ?? 0) + 1;
        } elseif (in_array('sent', $st, true)) {
            $cat = 'JÁ EMITIDA';
        } elseif (array_intersect(['pending','processing','manual'], $st)) {
            $cat = 'NA FILA';
        } elseif (in_array('error', $st, true)) {
            $cat = 'ERRO';
        } elseif (!$existsPay) {
            $cat = 'NÃO IMPORTADA';
            $faltam[] = $p;
        } else {
            $cat = 'NA FILA';
        }

        $stats[$companyId][$cat] = ($stats[$companyId][$cat] ?? 0) + 1;
    }
    $offset += $limit;
} while ($hasMore);

// ---- Relatório ----
foreach ($stats as $cid => $cats) {
    echo "\n## " . ($empNome[$cid] ?? "Empresa #{$cid}") . "\n";
    foreach (['JÁ EMITIDA','NA FILA','ERRO','NÃO IMPORTADA','SEM PRODUTO'] as $k) {
        if (!empty($cats[$k])) echo sprintf("   %-15s %d\n", $k, $cats[$k]);
    }
}
echo "\nTotal de pagamentos confirmados/recebidos no período: {$total}\n";

if ($semProduto) {
    echo "\nDescrições SEM PRODUTO (cadastrar/ajustar keyword):\n";
    arsort($semProduto);
    foreach (array_slice($semProduto, 0, 20, true) as $d => $n) {
        echo sprintf("   (%dx) %s\n", $n, mb_substr($d, 0, 80));
    }
}

echo "\nNÃO IMPORTADAS (nunca viraram registro): " . count($faltam) . "\n";

// ---- Envio (opcional) ----
if ($enviar && $faltam) {
    echo "\n--- Reinjetando " . count($faltam) . " venda(s) NÃO IMPORTADA(s) ---\n";
    $ok = 0;
    foreach ($faltam as $p) {
        $billing = (string)($p['billingType'] ?? '');
        $event = $billing === 'CREDIT_CARD' ? 'PAYMENT_CONFIRMED' : 'PAYMENT_RECEIVED';
        $body = json_encode(['event' => $event, 'payment' => $p, '_import' => 'auditoria-mes'], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($hookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'asaas-access-token: ' . $hookToken],
        ]);
        $resp = (string)curl_exec($ch); curl_close($ch);
        $tag = strpos($resp, '"enqueued":[{') !== false ? 'OK' : (strpos($resp, 'manual') !== false ? 'REVISAO' : 'OUTRO');
        if ($tag === 'OK') $ok++;
        echo sprintf("   %s venc=%s R$%s [%s]\n", $p['id'], $p['dueDate'] ?? '?', $p['value'] ?? '?', $tag);
        usleep(120000);
    }
    echo "\nReinjetadas: {$ok}. Rode o process-queue para emitir.\n";
} elseif ($faltam) {
    echo "(rode de novo com --enviar para reinjetar essas)\n";
}

echo "\nFim da auditoria.\n";
