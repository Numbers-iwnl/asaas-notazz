<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

use App\Auth;
use App\Helpers\Security;

Auth::start();
if (Auth::check()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf'] ?? '';
    if (!Security::csrfCheck((string)$csrf)) {
        $error = 'Sessão expirada, tente de novo.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        if (Auth::attempt($email, $pass)) {
            header('Location: /admin/dashboard.php');
            exit;
        }
        $error = 'Credenciais inválidas.';
    }
}
$token = Security::csrfToken();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — Notas WF</title>
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<div class="login-bg">
<form class="login-box" method="post" action="/admin/login.php">
  <h2>Notas WF</h2>
  <div class="sub">Painel administrativo</div>
  <?php if ($error): ?>
    <div class="alert error"><?= Security::e($error) ?></div>
  <?php endif; ?>
  <input type="hidden" name="_csrf" value="<?= Security::e($token) ?>">
  <div class="form-row">
    <label>E-mail</label>
    <input type="email" name="email" required autofocus value="<?= Security::e($_POST['email'] ?? '') ?>">
  </div>
  <div class="form-row">
    <label>Senha</label>
    <input type="password" name="password" required>
  </div>
  <button class="btn" style="width:100%;padding:11px" type="submit">Entrar</button>
</form>
</div>
</body>
</html>
