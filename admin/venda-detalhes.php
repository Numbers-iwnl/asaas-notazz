<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Database;
use App\Repositories\PaymentRepo;
use App\Helpers\Security;

$id = (int)($_GET['id'] ?? 0);
$p = PaymentRepo::find($id);
if (!$p) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Venda não encontrada.'];
    header('Location: /admin/vendas.php'); exit;
}
$docs = Database::all('SELECT * FROM notazz_documents WHERE payment_row_id = ? ORDER BY id ASC', [$id]);
$pageTitle = 'Venda #' . $id . ' — ' . ($p['customer_name'] ?? '');
ob_start();
?>
<div class="grid-2 mb-24">
  <div class="card">
    <div class="label">Dados Asaas</div>
    <table style="background:transparent;border:0">
      <tr><td class="muted">Payment ID</td><td><?= Security::e($p['asaas_payment_id']) ?></td></tr>
      <tr><td class="muted">Evento</td><td><?= Security::e($p['event']) ?></td></tr>
      <tr><td class="muted">Status</td><td><?= Security::e($p['status'] ?? '-') ?></td></tr>
      <tr><td class="muted">Forma</td><td><?= Security::e($p['billing_type'] ?? '-') ?></td></tr>
      <tr><td class="muted">Valor</td><td>R$ <?= number_format((float)$p['value'], 2, ',', '.') ?></td></tr>
      <tr><td class="muted">Valor líquido</td><td>R$ <?= number_format((float)$p['net_value'], 2, ',', '.') ?></td></tr>
      <tr><td class="muted">Vencimento</td><td><?= Security::e($p['due_date'] ?? '-') ?></td></tr>
      <tr><td class="muted">Pago em</td><td><?= Security::e($p['payment_date'] ?? '-') ?></td></tr>
      <tr><td class="muted">Parcela</td><td><?= Security::e(($p['installment_number'] ?? '-') . ' / ' . ($p['installment_count'] ?? '-')) ?></td></tr>
      <tr><td class="muted">Description</td><td><?= Security::e($p['description'] ?? '-') ?></td></tr>
    </table>
  </div>
  <div class="card">
    <div class="label">Cliente</div>
    <table style="background:transparent;border:0">
      <tr><td class="muted">Nome</td><td><?= Security::e($p['customer_name'] ?? '-') ?></td></tr>
      <tr><td class="muted">CPF/CNPJ</td><td><?= Security::e($p['customer_cpfcnpj'] ?? '-') ?> (<?= $p['customer_person_type'] ?? '?' ?>)</td></tr>
      <tr><td class="muted">E-mail</td><td><?= Security::e($p['customer_email'] ?? '-') ?></td></tr>
      <tr><td class="muted">Telefone</td><td><?= Security::e($p['customer_phone'] ?? '-') ?></td></tr>
      <tr><td class="muted">Endereço</td><td><?= Security::e(($p['customer_address'] ?? '') . ', ' . ($p['customer_address_number'] ?? '')) ?></td></tr>
      <tr><td class="muted">Bairro</td><td><?= Security::e($p['customer_province'] ?? '-') ?></td></tr>
      <tr><td class="muted">CEP</td><td><?= Security::e($p['customer_postal_code'] ?? '-') ?></td></tr>
      <tr><td class="muted">Cidade/UF</td><td><?= Security::e(($p['customer_city'] ?? '-') . ' / ' . ($p['customer_state'] ?? '-')) ?></td></tr>
    </table>
  </div>
</div>

<div class="tab-section">
  <h3>Documentos gerados desta venda</h3>
  <table>
    <thead><tr><th>#</th><th>Tipo</th><th>Status</th><th>Tentativas</th><th>Nº</th><th>Atualizada</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><?= (int)$d['id'] ?></td>
        <td><strong><?= strtoupper(Security::e($d['document_type'])) ?></strong></td>
        <td><span class="badge <?= Security::e($d['status']) ?>"><?= Security::e($d['status']) ?></span></td>
        <td><?= (int)$d['attempts'] ?></td>
        <td><?= Security::e($d['notazz_number'] ?? '-') ?></td>
        <td class="muted"><?= Security::e($d['updated_at']) ?></td>
        <td><a class="btn ghost btn-sm" href="/admin/nota-detalhes.php?id=<?= (int)$d['id'] ?>">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$docs): ?><tr><td colspan="7" class="center muted">Nenhum documento (produto não casado ou produto ignorado).</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="tab-section">
  <h3>Payload bruto recebido do Asaas</h3>
  <pre><?= Security::e($p['raw_payload'] ?? '') ?></pre>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
