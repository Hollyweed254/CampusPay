<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiRequestLogger;
use App\Core\AppException;
use PDOException;

/**
 * HMAC-SHA256 request authentication for /api/v1.
 *
 * Canonical string (newline separated):
 *   METHOD
 *   /path?query          (after APP_BASE_PATH)
 *   unix timestamp
 *   nonce
 *   sha256(raw body)
 *
 * The secret is decrypted only after the credential row is found. A valid
 * signature is required before its nonce is burned, so rejected requests
 * cannot consume a caller's nonces.
 */
final class ApiAuth
{
    private const MAX_SKEW_SECONDS = 300;
    private const HEADERS = [
        'key' => ['HTTP_X_CP_KEY', 'REDIRECT_HTTP_X_CP_KEY'],
        'timestamp' => ['HTTP_X_CP_TIMESTAMP', 'REDIRECT_HTTP_X_CP_TIMESTAMP'],
        'nonce' => ['HTTP_X_CP_NONCE', 'REDIRECT_HTTP_X_CP_NONCE'],
        'signature' => ['HTTP_X_CP_SIGNATURE', 'REDIRECT_HTTP_X_CP_SIGNATURE'],
    ];

    /** @return array{credential: array, application: array, body: string} */
    public static function authenticate(): array
    {
        ApiRequestLogger::begin();

        $keyId = self::header('key');
        $timestamp = self::header('timestamp');
        $nonce = self::header('nonce');
        $signature = strtolower(self::header('signature'));

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new AppException('auth_missing', 'Sign the request with X-CP-Key, X-CP-Timestamp, X-CP-Nonce and X-CP-Signature.', 401);
        }

        $credential = q(
            'SELECT c.*, a.name AS application_name, a.slug AS application_slug, a.status AS application_status,
                    a.settlement_model, a.cash_on_meeting_enabled, a.webhook_url
             FROM api_credentials c
             JOIN applications a ON a.id = c.application_id
             WHERE c.key_id = ? LIMIT 1',
            [$keyId]
        )->fetch();

        if ($credential === false) {
            throw new AppException('auth_invalid', 'Unknown API key.', 401);
        }
        ApiRequestLogger::identify((int) $credential['id'], (int) $credential['application_id']);

        if ($credential['status'] !== 'active') {
            throw new AppException('credential_revoked', 'This API key has been revoked.', 401);
        }
        if ($credential['expires_at'] !== null && strtotime($credential['expires_at'] . ' UTC') <= time()) {
            throw new AppException('credential_expired', 'This API key has expired.', 401);
        }
        if ($credential['application_status'] !== 'active') {
            throw new AppException('application_inactive', 'This application is suspended.', 403);
        }

        if (preg_match('/^\d{10}$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new AppException('timestamp_skew', 'The request timestamp is missing, malformed or more than 5 minutes from server time.', 401);
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $nonce) !== 1) {
            throw new AppException('invalid_nonce', 'Nonce must be 16-80 characters of A-Z, a-z, 0-9, _ or -.', 401);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new AppException('invalid_signature', 'Signature must be 64 lowercase hex characters.', 401);
        }

        $body = (string) file_get_contents('php://input');
        $secret = SecretBox::decrypt((string) $credential['secret_cipher']);
        $canonical = self::canonicalString(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            self::signedPath(),
            $timestamp,
            $nonce,
            $body
        );
        $expected = hash_hmac('sha256', $canonical, $secret);

        if (!hash_equals($expected, $signature)) {
            throw new AppException('signature_mismatch', 'The request signature does not match.', 401);
        }

        $limit = Setting::int('api_rate_limit_per_minute', 120);
        if ($limit > 0) {
            $recent = (int) q(
                'SELECT COUNT(*) FROM api_nonces
                 WHERE credential_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 60 SECOND)',
                [(int) $credential['id']]
            )->fetchColumn();
            if ($recent >= $limit) {
                throw new AppException('rate_limited', 'Too many requests for this API key. Retry after the current minute.', 429);
            }
        }

        try {
            q('INSERT INTO api_nonces (credential_id, nonce) VALUES (?, ?)', [(int) $credential['id'], $nonce]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new AppException('nonce_replay', 'This nonce was already used. Sign a new request.', 409);
            }
            throw $e;
        }
        q('DELETE FROM api_nonces WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)');
        q('UPDATE api_credentials SET last_used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $credential['id']]);

        return [
            'credential' => $credential,
            'application' => [
                'id' => (int) $credential['application_id'],
                'name' => $credential['application_name'],
                'slug' => $credential['application_slug'],
                'status' => $credential['application_status'],
                'settlement_model' => $credential['settlement_model'],
                'cash_on_meeting_enabled' => (int) $credential['cash_on_meeting_enabled'],
                'webhook_url' => $credential['webhook_url'],
            ],
            'body' => $body,
        ];
    }

    /** The exact bytes a client signs, given the request parts. */
    public static function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }

    /** The path a client signs: request path minus APP_BASE_PATH, plus the raw query string. */
    public static function signedPath(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = app_base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        return $path . ($query === '' ? '' : '?' . $query);
    }

    private static function header(string $name): string
    {
        foreach (self::HEADERS[$name] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return trim($_SERVER[$key]);
            }
        }
        return '';
    }
}
