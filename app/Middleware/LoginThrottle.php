<?php
declare(strict_types=1);

namespace App\Middleware;

final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_MINUTES = 15;

    public static function blocked(string $email, string $ip): bool
    {
        $count = q(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip = ? AND successful = 0
               AND attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)',
            [$email, $ip]
        )->fetchColumn();
        return (int) $count >= self::MAX_FAILURES;
    }

    public static function record(string $email, string $ip, bool $successful): void
    {
        q('INSERT INTO login_attempts (email, ip, successful) VALUES (?, ?, ?)', [$email, $ip, $successful ? 1 : 0]);
    }
}
