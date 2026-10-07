<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\PaymentRepo;
use App\Helpers\Security;
use App\Helpers\DateFilter;

$search = trim((string)($_GET['q'] ?? ''));
[$filterFrom, $filterTo] = DateFilter::fromRequest();
$rows = PaymentRepo::listRecent(300, $search ?: null, $filterFrom, $filterTo);
$filterExtra = $search ? ['q' => $search] : [];
$pageTitle = 'Vendas recebidas';
ob_start();
?>
<form method="get" class="search-bar">
  <?php if ($filterFrom): ?><input type="hidden" name="de" value="<?= Security::e($filterFrom) ?>"><?php endif; ?>
  <?php if ($filterTo): ?><input type="hidden" name="ate" value="<?= Security::e($filterTo) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="Buscar por descrição, nome, CPF/CNPJ ou ID do Asaas..." value="<?= Security::e($search) ?>">
  <button class="btn" type="submit">Buscar</button>
  <?php if ($search): ?><a href="/admin/vendas.php" class="btn ghost">Limpar busca</a><?php endif; ?>
</form>
<?php include __DIR__ . '/partials/_date_filter.php'; ?>

<table>
  <thead><tr><th>ID</th><th>Asaas Payment</th><th>Evento</th><th>Cliente</th><th>CPF/CNPJ</th><th>Valor</th><th>Descrição</th><th>Cobrança</th><th>Recebido</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td class="muted"><?= Security::e($p['asaas_payment_id']) ?></td>
      <td><?= Security::e($p['event']) ?></td>
      <td><?= Security::e($p['customer_name'] ?? '-') ?></td>
      <td><?= Security::e($p['customer_cpfcnpj'] ?? '-') ?></td>
      <td class="nowrap">R$ <?= number_format((float)$p['value'], 2, ',', '.') ?></td>
      <td><?= Security::e(mb_substr($p['description'] ?? '', 0, 70)) ?></td>
      <td><?= Security::e($p['billing_type'] ?? '-') ?></td>
      <td class="nowrap muted"><?= Security::e($p['received_at']) ?></td>
      <td><a href="/admin/venda-detalhes.php?id=<?= (int)$p['id'] ?>" class="btn ghost btn-sm">Ver</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="center muted">Nenhuma venda no período.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
