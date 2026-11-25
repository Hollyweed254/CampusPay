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

    /** Seconds an unfinished two-factor challenge stays valid. */
    private const TOTP_CHALLENGE_TTL = 600;
    /** Seconds the enrolment secret stays in the session awaiting confirmation. */
    private const TOTP_SETUP_TTL = 900;

    /**
     * Password accepted; hold the session at the second factor instead of
     * signing in. A fresh session id and CSRF token are issued so nothing
     * from the pre-2FA request can be reused.
     *
     * $enrol = true when the administrator has no TOTP yet and policy requires
     * it, so /login/2fa shows the enrolment step instead of the verify step.
     */
    public static function startTotpChallenge(int $adminId, bool $enrol): void
    {
        session_regenerate_id(true);
        $_SESSION['totp'] = ['admin_id' => $adminId, 'started_at' => time(), 'enrol' => $enrol];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /** @return array{admin_id:int, started_at:int, enrol:bool}|null */
    public static function totpChallenge(): ?array
    {
        $challenge = $_SESSION['totp'] ?? null;
        if (!is_array($challenge) || !isset($challenge['admin_id'])) {
            return null;
        }
        if ((int) $challenge['started_at'] < time() - self::TOTP_CHALLENGE_TTL) {
            self::clearTotpChallenge();
            return null;
        }
        return [
            'admin_id' => (int) $challenge['admin_id'],
            'started_at' => (int) $challenge['started_at'],
            'enrol' => (bool) ($challenge['enrol'] ?? false),
        ];
    }

    public static function clearTotpChallenge(): void
    {
        unset($_SESSION['totp']);
    }

    /** Park a not-yet-confirmed enrolment secret in the session. */
    public static function startTotpSetup(int $adminId, string $secret): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['totp_setup'] = ['admin_id' => $adminId, 'secret' => $secret, 'created_at' => time()];
    }

    public static function totpSetupSecret(int $adminId): ?string
    {
        $setup = $_SESSION['totp_setup'] ?? null;
        if (!is_array($setup) || (int) ($setup['admin_id'] ?? 0) !== $adminId) {
            return null;
        }
        if ((int) ($setup['created_at'] ?? 0) < time() - self::TOTP_SETUP_TTL) {
            self::clearTotpSetup();
            return null;
        }
        return is_string($setup['secret'] ?? null) ? $setup['secret'] : null;
    }

    public static function clearTotpSetup(): void
    {
        unset($_SESSION['totp_setup']);
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
