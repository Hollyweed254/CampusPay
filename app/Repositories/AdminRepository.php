<?php
declare(strict_types=1);

namespace App\Repositories;

final class AdminRepository
{
    public static function findByEmail(string $email): ?array
    {
        $row = q('SELECT * FROM administrators WHERE email = ? LIMIT 1', [$email])->fetch();
        return $row === false ? null : $row;
    }

    public static function find(int $id): ?array
    {
        $row = q('SELECT * FROM administrators WHERE id = ? LIMIT 1', [$id])->fetch();
        return $row === false ? null : $row;
    }

    public static function markLogin(int $id): void
    {
        q('UPDATE administrators SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    /** Store (or replace) the encrypted TOTP seed; enrolment stays pending. */
    public static function setTotpSecret(int $id, string $secretCipher): void
    {
        q('UPDATE administrators SET totp_secret = ?, totp_enabled_at = NULL, totp_last_counter = NULL WHERE id = ?', [$secretCipher, $id]);
    }

    public static function enableTotp(int $id, int $counter): void
    {
        q('UPDATE administrators SET totp_enabled_at = NOW(), totp_last_counter = ? WHERE id = ?', [$counter, $id]);
    }

    public static function markTotpCounter(int $id, int $counter): void
    {
        q('UPDATE administrators SET totp_last_counter = ? WHERE id = ?', [$counter, $id]);
    }

    public static function disableTotp(int $id): void
    {
        q('UPDATE administrators SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_counter = NULL WHERE id = ?', [$id]);
    }

    public static function countActive(): int
    {
        return (int) q("SELECT COUNT(*) FROM administrators WHERE status = 'active'")->fetchColumn();
    }
}
