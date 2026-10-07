<?php
use App\Auth;
use App\Helpers\Security;

$current = basename($_SERVER['SCRIPT_NAME']);
$user = Auth::user();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= Security::e($pageTitle ?? 'Painel') ?> — Notas WF</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= filemtime(__DIR__.'/../assets/style.css') ?>">
</head>
<body>
<div class="shell">
<aside class="sidebar">
  <div class="brand">Notas WF<small>Asaas → Notazz</small></div>
  <ul class="menu">
    <li><a href="/admin/dashboard.php" class="<?= $current==='dashboard.php'?'active':'' ?>">Dashboard</a></li>
    <li><a href="/admin/vendas.php" class="<?= $current==='vendas.php'?'active':'' ?>">Vendas recebidas</a></li>
    <li><a href="/admin/notas-pendentes.php" class="<?= $current==='notas-pendentes.php'?'active':'' ?>">Notas pendentes</a></li>
    <li><a href="/admin/notas-emitidas.php" class="<?= $current==='notas-emitidas.php'?'active':'' ?>">Notas emitidas</a></li>
    <li><a href="/admin/notas-erro.php" class="<?= $current==='notas-erro.php'?'active':'' ?>">Notas com erro</a></li>
    <li><a href="/admin/revisao.php" class="<?= $current==='revisao.php'?'active':'' ?>">Revisão (legado)</a></li>
    <li><a href="/admin/logs.php" class="<?= $current==='logs.php'?'active':'' ?>">Logs</a></li>
    <li><a href="/admin/webhooks.php" class="<?= $current==='webhooks.php'?'active':'' ?>">Webhooks recebidos</a></li>
    <li><a href="/admin/produtos.php" class="<?= $current==='produtos.php'?'active':'' ?>">Produtos / Regras</a></li>
    <li><a href="/admin/empresas.php" class="<?= $current==='empresas.php'?'active':'' ?>">Empresas</a></li>
  </ul>
</aside>
<main class="main">
  <div class="top">
    <h1><?= Security::e($pageTitle ?? '') ?></h1>
    <div class="user">
      <?php
        // Seletor de empresa (multi-empresa). Mantém a página atual, troca só o filtro.
        try {
            $ccCompanies = \App\Helpers\CompanyContext::options();
            $ccCurrent   = (int)($_SESSION['company_filter'] ?? 0);
        } catch (\Throwable $e) { $ccCompanies = []; $ccCurrent = 0; }
        if (count($ccCompanies) > 1):
      ?>
      <form method="get" style="display:inline-block;margin-right:14px">
        <?php foreach ($_GET as $gk => $gv): if ($gk === 'empresa' || !is_scalar($gv)) continue; ?>
          <input type="hidden" name="<?= Security::e((string)$gk) ?>" value="<?= Security::e((string)$gv) ?>">
        <?php endforeach; ?>
        <select name="empresa" onchange="this.form.submit()" style="width:auto;padding:6px 10px">
          <option value="0" <?= $ccCurrent === 0 ? 'selected' : '' ?>>Todas as empresas</option>
          <?php foreach ($ccCompanies as $cc): ?>
            <option value="<?= (int)$cc['id'] ?>" <?= $ccCurrent === (int)$cc['id'] ? 'selected' : '' ?>>
              <?= Security::e($cc['trade_name'] ?: $cc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <?= Security::e($user['name'] ?? '') ?>
      <a href="/admin/logout.php">Sair</a>
    </div>
  </div>
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert <?= Security::e($_SESSION['flash']['type'] ?? 'info') ?>">
      <?= Security::e($_SESSION['flash']['msg']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
  <?= $pageContent ?? '' ?>
</main>
</div>
</body>
</html>
