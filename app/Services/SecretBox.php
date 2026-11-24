<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;

/** AES-256-GCM at rest for API secrets, keyed by APP_KEY. */
final class SecretBox
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the API secret.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new AppException('credential_unreadable', 'Stored API credential cannot be read.', 500);
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new AppException('credential_unreadable', 'Stored API credential cannot be read.', 500);
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new AppException('credential_unreadable', 'Stored API credential cannot be decrypted. Rotate it.', 500);
        }
        return $plain;
    }

    private static function key(): string
    {
        $encoded = (string) env('APP_KEY', '');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_KEY must be 32 base64-encoded bytes.');
        }
        return $key;
    }
}
