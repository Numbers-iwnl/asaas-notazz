<?php
declare(strict_types=1);

/**
 * Cria o primeiro usuário admin (execute UMA vez via cPanel/terminal).
 *
 * Uso:
 *   php database/create-admin.php "Ana Souza" "email@dominio.com" "minhaSenhaForte"
 *
 * Em seguida, REMOVA este arquivo do servidor.
 */
require __DIR__ . '/../app/Bootstrap.php';
\App\Bootstrap::init();

if ($argc < 4) {
    fwrite(STDERR, "Uso: php database/create-admin.php \"Nome\" \"email\" \"senha\"\n");
    exit(1);
}
[$_, $name, $email, $password] = $argv;

if (strlen($password) < 8) {
    fwrite(STDERR, "Senha deve ter no mínimo 8 caracteres.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$exists = \App\Database::one('SELECT id FROM users WHERE email = ?', [$email]);
if ($exists) {
    \App\Database::update('users', [
        'name' => $name,
        'password_hash' => $hash,
        'active' => 1,
    ], 'id = ?', [$exists['id']]);
    echo "Usuário {$email} atualizado (ID {$exists['id']}).\n";
} else {
    $id = \App\Database::insert('users', [
        'name' => $name,
        'email' => $email,
        'password_hash' => $hash,
        'active' => 1,
    ]);
    echo "Usuário {$email} criado com ID {$id}.\n";
}
echo "Pronto. ⚠️ Remova este arquivo do servidor após o uso.\n";
