<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) env('SESSION_NAME', 'CPSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => env('APP_ENV') === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public static function admin(): ?array
    {
        $admin = $_SESSION['admin'] ?? null;
        return is_array($admin) && isset($admin['id']) ? $admin : null;
    }

    public static function login(array $admin): void
    {
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => (int) $admin['id'],
            'name' => (string) $admin['name'],
            'email' => (string) $admin['email'],
            'role' => (string) $admin['role'],
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public static function requireAdmin(): void
    {
        if (self::admin() === null) {
            redirect('/login');
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireAdmin();
        if ((self::admin()['role'] ?? '') !== $role) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Forbidden'], 'layouts/admin');
            exit;
        }
    }
}
