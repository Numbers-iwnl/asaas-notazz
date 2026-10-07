<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\LogRepo;
use App\Helpers\Security;

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $row = LogRepo::findWebhook($id);
    $pageTitle = 'Webhook #' . $id;
    ob_start();
    if (!$row): ?>
      <div class="alert error">Webhook não encontrado.</div>
    <?php else: ?>
      <a class="btn ghost mb-12" href="/admin/webhooks.php">← Voltar</a>
      <div class="grid-2 mb-24">
        <div class="card">
          <div class="label">Resumo</div>
          <table style="background:transparent;border:0">
            <tr><td class="muted">Recebido em</td><td><?= Security::e($row['received_at']) ?></td></tr>
            <tr><td class="muted">IP</td><td><?= Security::e($row['remote_ip'] ?? '-') ?></td></tr>
            <tr><td class="muted">Método</td><td><?= Security::e($row['method'] ?? '-') ?></td></tr>
            <tr><td class="muted">Token válido?</td><td><?= $row['token_valid'] ? '<span class="badge sent">SIM</span>' : '<span class="badge error">NÃO</span>' ?></td></tr>
            <tr><td class="muted">JSON válido?</td><td><?= $row['json_valid'] ? 'SIM' : 'NÃO' ?></td></tr>
            <tr><td class="muted">Evento</td><td><?= Security::e($row['event'] ?? '-') ?></td></tr>
            <tr><td class="muted">Payment ID</td><td><?= Security::e($row['asaas_payment_id'] ?? '-') ?></td></tr>
            <tr><td class="muted">HTTP resp.</td><td><?= (int)$row['response_code'] ?></td></tr>
          </table>
        </div>
        <div class="card">
          <div class="label">Resposta enviada</div>
          <pre style="margin-top:8px"><?= Security::e($row['response_body'] ?? '') ?></pre>
        </div>
      </div>
      <div class="tab-section"><h3>Headers</h3><pre><?= Security::e($row['headers'] ?? '') ?></pre></div>
      <div class="tab-section"><h3>Body bruto</h3><pre><?= Security::e($row['raw_body'] ?? '') ?></pre></div>
    <?php endif;
    $pageContent = ob_get_clean();
    require __DIR__ . '/partials/layout.php';
    return;
}

[$filterFrom, $filterTo] = \App\Helpers\DateFilter::fromRequest();
$rows = LogRepo::listWebhooks(500, $filterFrom, $filterTo);
$pageTitle = 'Webhooks recebidos';
ob_start();
include __DIR__ . '/partials/_date_filter.php';
?>
<table>
  <thead><tr><th>#</th><th>Quando</th><th>IP</th><th>Evento</th><th>Payment</th><th>Token</th><th>JSON</th><th>HTTP</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $w): ?>
    <tr>
      <td><?= (int)$w['id'] ?></td>
      <td class="muted nowrap"><?= Security::e($w['received_at']) ?></td>
      <td class="muted"><?= Security::e($w['remote_ip'] ?? '-') ?></td>
      <td><?= Security::e($w['event'] ?? '-') ?></td>
      <td class="muted"><?= Security::e($w['asaas_payment_id'] ?? '-') ?></td>
      <td><?= $w['token_valid'] ? '<span class="badge sent">OK</span>' : '<span class="badge error">INV</span>' ?></td>
      <td><?= $w['json_valid'] ? 'OK' : 'INV' ?></td>
      <td><?= (int)$w['response_code'] ?></td>
      <td><a class="btn ghost btn-sm" href="/admin/webhooks.php?id=<?= (int)$w['id'] ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="center muted">Nenhum webhook recebido ainda.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
