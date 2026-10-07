<?php
declare(strict_types=1);
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();
header('Location: ' . (\App\Auth::check() ? '/admin/dashboard.php' : '/admin/login.php'));
exit;
