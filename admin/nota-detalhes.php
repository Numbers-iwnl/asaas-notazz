<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\DocumentRepo;
use App\Helpers\Security;

$id = (int)($_GET['id'] ?? 0);
$doc = DocumentRepo::findFull($id);
if (!$doc) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Documento não encontrado.'];
    header('Location: /admin/dashboard.php'); exit;
}

// POST: ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfCheck((string)($_POST['_csrf'] ?? ''))) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'CSRF inválido.'];
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'reset') {
            DocumentRepo::reset($id);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Documento reposto para fila. Cron deve reprocessar em até 1 min.'];
        } elseif ($action === 'ignore') {
            DocumentRepo::markIgnored($id, 'Marcado manualmente pelo painel');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Documento marcado como ignorado.'];
        } elseif ($action === 'set_foreign') {
            // Define país (ISO-2) e documento estrangeiro no pagamento; reenvia TODOS
            // os documentos manual/error deste pagamento para a fila.
            $country = strtoupper(trim((string)($_POST['country'] ?? '')));
            $fdoc    = trim((string)($_POST['foreign_doc'] ?? ''));
            if (!preg_match('/^[A-Z]{2}$/', $country) || $country === 'BR') {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'País inválido. Use o código de 2 letras (ex: PT, US, AR).'];
            } else {
                \App\Database::update('asaas_payments', [
                    'customer_country'     => $country,
                    'customer_foreign_doc' => $fdoc !== '' ? $fdoc : null,
                ], 'id = ?', [(int)$doc['payment_row_id']]);
                \App\Database::run(
                    "UPDATE notazz_documents
                     SET status='pending', locked_at=NULL, next_retry_at=NULL, attempts=0, last_error=NULL
                     WHERE payment_row_id = ? AND status IN ('manual','error')",
                    [(int)$doc['payment_row_id']]
                );
                $_SESSION['flash'] = ['type'=>'success','msg'=>"País {$country} definido. Nota(s) deste pagamento reenviada(s) para emissão."];
            }
        }
    }
    header('Location: /admin/nota-detalhes.php?id=' . $id); exit;
}

$csrf = Security::csrfToken();
$pageTitle = strtoupper($doc['document_type']) . ' #' . $id;
ob_start();
?>
<div class="flex-between mb-24">
  <div>
    <span class="badge <?= Security::e($doc['status']) ?>"><?= Security::e($doc['status']) ?></span>
    <strong style="margin-left:8px"><?= Security::e($doc['customer_name'] ?? '') ?></strong>
    <span class="muted"> · Nota: <strong>R$ <?= number_format((float)($doc['document_value'] ?? 0), 2, ',', '.') ?></strong> · Venda: R$ <?= number_format((float)($doc['sale_value'] ?? 0), 2, ',', '.') ?></span>
  </div>
  <div>
    <form method="post" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
      <input type="hidden" name="action" value="reset">
      <button class="btn">Reprocessar</button>
    </form>
    <?php if ($doc['status'] !== 'ignored'): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
      <input type="hidden" name="action" value="ignore">
      <button class="btn ghost">Ignorar</button>
    </form>
    <?php endif; ?>
    <a class="btn ghost" href="javascript:history.back()">Voltar</a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="label">Dados gerais</div>
    <table style="margin-top:8px;background:transparent;border:0">
      <tr><td class="muted">Asaas Payment ID</td><td><?= Security::e($doc['asaas_payment_id']) ?></td></tr>
      <tr><td class="muted">Tipo</td><td><strong><?= strtoupper(Security::e($doc['document_type'])) ?></strong></td></tr>
      <tr><td class="muted">Status</td><td><span class="badge <?= Security::e($doc['status']) ?>"><?= Security::e($doc['status']) ?></span></td></tr>
      <tr><td class="muted">Tentativas</td><td><?= (int)$doc['attempts'] ?></td></tr>
      <tr><td class="muted">Cobrança Asaas</td><td><?= Security::e($doc['billing_type'] ?? '-') ?></td></tr>
      <tr><td class="muted">External ID</td><td><?= Security::e($doc['notazz_external_id'] ?? '-') ?></td></tr>
      <tr><td class="muted">Notazz Doc ID</td><td><?= Security::e($doc['notazz_document_id'] ?? '-') ?></td></tr>
      <tr><td class="muted">Nº NF</td><td><?= Security::e($doc['notazz_number'] ?? '-') ?></td></tr>
      <tr><td class="muted">Chave / Cód. Verif.</td><td style="word-break:break-all"><?= Security::e($doc['notazz_key'] ?? '-') ?></td></tr>
      <tr><td class="muted">PDF</td><td><?php if ($doc['pdf_url']): ?><a target="_blank" href="<?= Security::e($doc['pdf_url']) ?>">Abrir PDF</a><?php else: ?>-<?php endif; ?></td></tr>
      <tr><td class="muted">XML</td><td><?php if ($doc['xml_url']): ?><a target="_blank" href="<?= Security::e($doc['xml_url']) ?>">Abrir XML</a><?php else: ?>-<?php endif; ?></td></tr>
      <tr><td class="muted">Criada</td><td><?= Security::e($doc['created_at']) ?></td></tr>
      <tr><td class="muted">Emitida</td><td><?= Security::e($doc['sent_at'] ?? '-') ?></td></tr>
    </table>
  </div>
  <div class="card">
    <div class="label">Cliente / Pagamento</div>
    <table style="margin-top:8px;background:transparent;border:0">
      <tr><td class="muted">Nome</td><td><?= Security::e($doc['customer_name'] ?? '-') ?></td></tr>
      <tr><td class="muted">CPF/CNPJ</td><td><?= Security::e($doc['customer_cpfcnpj'] ?? '-') ?></td></tr>
      <tr><td class="muted">E-mail</td><td><?= Security::e($doc['customer_email'] ?? '-') ?></td></tr>
      <tr><td class="muted">Valor desta nota</td><td><strong>R$ <?= number_format((float)($doc['document_value'] ?? 0), 2, ',', '.') ?></strong></td></tr>
      <tr><td class="muted">Valor da venda</td><td>R$ <?= number_format((float)($doc['sale_value'] ?? 0), 2, ',', '.') ?> <span class="muted">(dividido entre NF-e e NFS-e)</span></td></tr>
      <tr><td class="muted">Descrição</td><td><?= Security::e($doc['description'] ?? '-') ?></td></tr>
      <tr><td class="muted">Evento</td><td><?= Security::e($doc['event'] ?? '-') ?></td></tr>
    </table>
  </div>
</div>

<?php if (!empty($doc['last_error'])): ?>
<div class="tab-section">
  <h3>Último erro</h3>
  <div class="alert error"><?= Security::e($doc['last_error']) ?></div>
</div>
<?php endif; ?>

<?php if (empty($doc['customer_cpfcnpj'])): ?>
<div class="tab-section">
  <h3>Cliente estrangeiro (sem CPF/CNPJ)</h3>
  <div class="card">
    <p class="muted" style="margin-top:0">Este cliente não tem CPF/CNPJ no Asaas. Informe o <strong>país</strong> (código de 2 letras: PT, US, AR, ES...) e, se tiver, o documento estrangeiro (NIF/passaporte). Ao salvar, todas as notas deste pagamento voltam para a fila e serão emitidas como <strong>tomador estrangeiro</strong>.</p>
    <form method="post" class="grid-3" style="align-items:end">
      <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
      <input type="hidden" name="action" value="set_foreign">
      <div class="form-row mb-0">
        <label>País (ISO-2) *</label>
        <input type="text" name="country" maxlength="2" placeholder="ex: PT" required
               value="<?= Security::e($doc['customer_country'] ?? '') ?>" style="text-transform:uppercase">
      </div>
      <div class="form-row mb-0">
        <label>Documento estrangeiro (opcional)</label>
        <input type="text" name="foreign_doc" placeholder="NIF / passaporte"
               value="<?= Security::e($doc['customer_foreign_doc'] ?? '') ?>">
      </div>
      <div class="form-row mb-0">
        <button class="btn" type="submit">Salvar país e reemitir</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="tab-section">
  <h3>Payload enviado ao Notazz</h3>
  <pre><?= Security::e($doc['payload_sent'] ?? '(ainda não enviado)') ?></pre>
</div>

<div class="tab-section">
  <h3>Resposta do Notazz</h3>
  <pre><?= Security::e($doc['payload_response'] ?? '(sem resposta ainda)') ?></pre>
</div>

<div class="tab-section">
  <h3>Payload bruto do Asaas</h3>
  <pre><?= Security::e($doc['raw_payload'] ?? '') ?></pre>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
