<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\LogRepo;
use App\Helpers\Security;
use App\Helpers\DateFilter;

$level = $_GET['level'] ?? null;
if ($level && !in_array($level, ['info','warning','error','debug'], true)) $level = null;
[$filterFrom, $filterTo] = DateFilter::fromRequest();
$rows = LogRepo::listProcessing(500, $level, $filterFrom, $filterTo);
$filterExtra = $level ? ['level' => $level] : [];
$pageTitle = 'Logs';
ob_start();
?>
<div class="mb-12">
  <a class="btn <?= !$level?'':'ghost' ?> btn-sm" href="/admin/logs.php">Todos</a>
  <a class="btn <?= $level==='info'?'':'ghost' ?> btn-sm" href="/admin/logs.php?level=info">Info</a>
  <a class="btn <?= $level==='warning'?'':'ghost' ?> btn-sm" href="/admin/logs.php?level=warning">Warning</a>
  <a class="btn <?= $level==='error'?'':'ghost' ?> btn-sm" href="/admin/logs.php?level=error">Error</a>
</div>
<?php include __DIR__ . '/partials/_date_filter.php'; ?>
<table>
  <thead><tr><th>Quando</th><th>Nível</th><th>Origem</th><th>Mensagem</th><th>Payment</th><th>Doc</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $l): ?>
    <tr>
      <td class="muted nowrap"><?= Security::e($l['created_at']) ?></td>
      <td><span class="badge <?= $l['level']==='error'?'error':($l['level']==='warning'?'pending':'processing') ?>"><?= Security::e($l['level']) ?></span></td>
      <td><?= Security::e($l['source']) ?></td>
      <td><?= Security::e($l['message']) ?></td>
      <td class="muted"><?= Security::e($l['asaas_payment_id'] ?? '-') ?></td>
      <td><?php if ($l['document_id']): ?><a href="/admin/nota-detalhes.php?id=<?= (int)$l['document_id'] ?>">#<?= (int)$l['document_id'] ?></a><?php else: ?>-<?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="center muted">Nenhum log.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
