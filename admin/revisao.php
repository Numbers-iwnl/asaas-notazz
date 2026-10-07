<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
\App\Auth::require();

use App\Repositories\DocumentRepo;
use App\Helpers\DateFilter;
use App\Helpers\Security;

// Ações: emitir (manda pra fila) ou ignorar uma nota legada/manual
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfCheck((string)($_POST['_csrf'] ?? ''))) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Sessão expirada, tente de novo.'];
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $doc = $id ? DocumentRepo::find($id) : null;
        if ($doc && $doc['status'] === 'manual') {
            if ($action === 'emitir') {
                DocumentRepo::reset($id); // vira 'pending' -> cron emite
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Documento #{$id} enviado para emissão. O cron emite em até 10 min."];
            } elseif ($action === 'ignorar') {
                DocumentRepo::markIgnored($id, 'Ignorado manualmente na revisão de legado');
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Documento #{$id} ignorado."];
            }
        }
    }
    $qs = http_build_query(array_filter(['de'=>$_GET['de']??null,'ate'=>$_GET['ate']??null]));
    header('Location: /admin/revisao.php' . ($qs ? "?$qs" : '')); exit;
}

[$filterFrom, $filterTo] = DateFilter::fromRequest();
$companyId = \App\Helpers\CompanyContext::currentId();
$rows = DocumentRepo::listByStatus('manual', 500, $filterFrom, $filterTo, $companyId);
$csrf = Security::csrfToken();
$pageTitle = 'Revisão manual (legado)';
ob_start();
?>
<div class="alert info mb-24">
  <strong>O que é esta tela:</strong> aqui ficam os documentos de pagamentos <em>antigos</em> (parcelas que caíram há mais de
  <?= (int)\App\Bootstrap::config('queue.legacy_window_days', 10) ?> dias, ou eventos reenviados em massa pelo Asaas).
  Eles <strong>não são emitidos automaticamente</strong>. Revise e clique em <strong>Emitir</strong> só nos que realmente
  precisam de nota (evita duplicar o que o financeiro já fez na mão).
</div>

<?php include __DIR__ . '/partials/_date_filter.php'; ?>

<table>
  <thead><tr><th>#</th><th>Tipo</th><th>Cliente</th><th>Valor venda</th><th>Descrição</th><th>Criada</th><th>Ações</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><strong><?= strtoupper(Security::e($r['document_type'])) ?></strong></td>
        <td><?= Security::e($r['customer_name'] ?? '-') ?><div class="muted" style="font-size:11px"><?= Security::e($r['customer_cpfcnpj'] ?? '') ?></div></td>
        <td class="nowrap">R$ <?= number_format((float)($r['sale_value'] ?? 0), 2, ',', '.') ?></td>
        <td><?= Security::e(mb_substr($r['description'] ?? '', 0, 55)) ?></td>
        <td class="nowrap muted"><?= Security::e($r['created_at']) ?></td>
        <td class="nowrap">
          <form method="post" style="display:inline" onsubmit="return confirm('Emitir esta nota? Ela vai pra fila e será emitida no Notazz.');">
            <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="action" value="emitir">
            <button class="btn btn-sm" type="submit">Emitir</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= Security::e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="action" value="ignorar">
            <button class="btn ghost btn-sm" type="submit">Ignorar</button>
          </form>
          <a class="btn ghost btn-sm" href="/admin/nota-detalhes.php?id=<?= (int)$r['id'] ?>">Ver</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="center muted">Nada para revisar neste período. 🎉</td></tr><?php endif; ?>
  </tbody>
</table>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/partials/layout.php';
