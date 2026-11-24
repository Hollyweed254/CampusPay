<?php
declare(strict_types=1);

namespace App\Services;

final class Setting
{
    private static array $cache = [];

    public static function get(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, self::$cache)) {
            $value = q('SELECT value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])->fetchColumn();
            self::$cache[$key] = $value === false ? $default : (string) $value;
        }
        return self::$cache[$key];
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, (string) $default);
        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public static function set(string $key, string $value): void
    {
        q(
            'INSERT INTO system_settings (setting_key, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    public static function all(): array
    {
        $rows = q('SELECT setting_key, value, updated_at FROM system_settings ORDER BY setting_key')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row;
        }
        return $out;
    }
}
