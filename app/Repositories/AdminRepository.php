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

    public static function markLogin(int $id): void
    {
        q('UPDATE administrators SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function countActive(): int
    {
        return (int) q("SELECT COUNT(*) FROM administrators WHERE status = 'active'")->fetchColumn();
    }
}
