<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Database;
use App\Repositories\DocumentRepo;
use App\Helpers\Security;
use App\Helpers\DateFilter;
use App\Helpers\CompanyContext;

[$filterFrom, $filterTo] = DateFilter::fromRequest();
$companyId = CompanyContext::currentId();
$companySql = $companyId ? ' AND d.company_id = ' . (int)$companyId : '';
$stats = DocumentRepo::statsByStatus($filterFrom, $filterTo, $companyId);
$pendingCount = $stats['pending']['count'] + $stats['processing']['count'];
$pendingValue = $stats['pending']['value'] + $stats['processing']['value'];
$sentCount    = $stats['sent']['count'];
$sentValue    = $stats['sent']['value'];
$errorCount   = $stats['error']['count'];
$errorValue   = $stats['error']['value'];
$ignoredCount = $stats['ignored']['count'];

[$salesDateSql, $salesDateBind] = DateFilter::clause('p.received_at', $filterFrom, $filterTo);
$salesCompanySql = $companyId ? ' AND pr.company_id = ' . (int)$companyId : '';
$sales = Database::one(
    'SELECT COUNT(*) c, COALESCE(SUM(p.value),0) v
     FROM asaas_payments p
     LEFT JOIN products pr ON pr.id = p.matched_product_id
     WHERE 1=1' . $salesDateSql . $salesCompanySql,
    $salesDateBind
) ?: ['c'=>0,'v'=>0];
$salesCount = (int)$sales['c'];
$salesValue = (float)$sales['v'];

$prodCompanySql = $companyId ? ' AND company_id = ' . (int)$companyId : '';
$totalProducts = (int)(Database::one('SELECT COUNT(*) c FROM products WHERE active=1 AND ignored=0' . $prodCompanySql)['c'] ?? 0);

$monthly = DocumentRepo::monthlyEmitted(6, $companyId);
$chartLabels = [];
$chartValues = [];
$mesPt = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
foreach ($monthly as $m) {
    [$y, $mo] = explode('-', $m['ym']);
    $chartLabels[] = ($mesPt[(int)$mo] ?? $mo) . '/' . substr($y, 2);
    $chartValues[] = round((float)$m['v'], 2);
}

$recent = Database::all(
    'SELECT d.*, p.customer_name, p.value AS sale_value, p.description
     FROM notazz_documents d
     JOIN asaas_payments p ON p.id = d.payment_row_id
     WHERE 1=1' . $companySql . '
     ORDER BY d.id DESC LIMIT 12'
);

$brl = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');

$pageTitle = 'Dashboard';
ob_start();
include __DIR__ . '/partials/_date_filter.php';
if ($filterFrom || $filterTo): ?>
  <div class="alert info">Mostrando dados de <strong><?= Security::e($filterFrom ?: 'início') ?></strong> até <strong><?= Security::e($filterTo ?: 'hoje') ?></strong>. O gráfico mensal abaixo sempre mostra os últimos 6 meses.</div>
<?php endif; ?>
<div class="dash-cards">
  <div class="dash-card blue">
    <div class="dc-top"><span class="dc-num"><?= $pendingCount ?></span><span class="dc-ico">&#9783;</span></div>
    <div class="dc-label">Notas pendentes</div>
    <div class="dc-foot"><?= $brl($pendingValue) ?></div>
  </div>
  <div class="dash-card green">
    <div class="dc-top"><span class="dc-num"><?= $sentCount ?></span><span class="dc-ico">&#10003;</span></div>
    <div class="dc-label">Notas emitidas</div>
    <div class="dc-foot"><?= $brl($sentValue) ?></div>
  </div>
  <div class="dash-card red">
    <div class="dc-top"><span class="dc-num"><?= $errorCount ?></span><span class="dc-ico">&#9888;</span></div>
    <div class="dc-label">Notas com erro</div>
    <div class="dc-foot"><?= $brl($errorValue) ?></div>
  </div>
  <div class="dash-card purple">
    <div class="dc-top"><span class="dc-num"><?= $salesCount ?></span><span class="dc-ico">&#36;</span></div>
    <div class="dc-label">Vendas recebidas</div>
    <div class="dc-foot"><?= $brl($salesValue) ?></div>
  </div>
</div>

<div class="dash-mini">
  <div class="mini-card"><span class="mini-label">Produtos ativos</span><span class="mini-val"><?= $totalProducts ?></span></div>
  <div class="mini-card"><span class="mini-label">Ignoradas</span><span class="mini-val"><?= $ignoredCount ?></span></div>
  <div class="mini-card"><span class="mini-label">Ticket médio venda</span><span class="mini-val"><?= $salesCount ? $brl($salesValue/$salesCount) : 'R$ 0,00' ?></span></div>
</div>

<div class="card" style="margin-bottom:24px">
  <div class="label" style="margin-bottom:14px">Total emitido (R$) por mês</div>
  <?php if ($chartValues): ?>
    <canvas id="emitChart" height="90"></canvas>
  <?php else: ?>
    <div class="center muted" style="padding:30px">Ainda não há notas emitidas para exibir o gráfico.</div>
  <?php endif; ?>
</div>

<div class="tab-section">
  <h3>Últimas notas</h3>
  <table>
    <thead><tr><th>#</th><th>Tipo</th><th>Cliente</th><th>Valor nota</th><th>Venda</th><th>Descrição</th><th>Status</th><th>Criada</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><strong><?= strtoupper(Security::e($r['document_type'])) ?></strong></td>
        <td><?= Security::e($r['customer_name'] ?? '-') ?></td>
        <td class="nowrap"><strong><?= $brl($r['document_value'] ?? 0) ?></strong></td>
        <td class="nowrap muted"><?= $brl($r['sale_value'] ?? 0) ?></td>
        <td><?= Security::e(mb_substr($r['description'] ?? '', 0, 50)) ?></td>
        <td><span class="badge <?= Security::e($r['status']) ?>"><?= Security::e($r['status']) ?></span></td>
        <td class="nowrap muted"><?= Security::e($r['created_at']) ?></td>
        <td><a href="/admin/nota-detalhes.php?id=<?= (int)$r['id'] ?>" class="btn ghost btn-sm">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?>
      <tr><td colspan="9" class="center muted">Ainda não há notas. Aguarde o primeiro webhook do Asaas.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($chartValues): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('emitChart');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Emitido (R$)',
      data: <?= json_encode($chartValues) ?>,
      backgroundColor: '#10b981',
      borderRadius: 6,
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      y: { ticks: { color: '#9aa7c2', callback: v => 'R$ ' + v.toLocaleString('pt-BR') }, grid: { color: '#27355a' } },
      x: { ticks: { color: '#9aa7c2' }, grid: { display: false } }
    }
  }
});
</script>
<?php endif; ?>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
