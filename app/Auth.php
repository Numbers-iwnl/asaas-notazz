<?php
declare(strict_types=1);

namespace App;

use App\Helpers\Security;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $name = Bootstrap::config('security.session_name', 'NOTASADM');
        $lifetime = (int)Bootstrap::config('security.session_lifetime', 14400);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = Database::one('SELECT id, name, email, password_hash, active FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user || (int)$user['active'] !== 1) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'login_at' => time(),
        ];
        return true;
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return self::check() ? $_SESSION['user'] : null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function require(): void
    {
        if (!self::check()) {
            header('Location: ' . Bootstrap::config('app.base_url') . '/admin/login.php');
            exit;
        }
    }
}
