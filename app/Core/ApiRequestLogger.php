<?php
declare(strict_types=1);

namespace App\Core;

/** Best-effort request log for /api/v1, written at shutdown so json_out() exits are captured. */
final class ApiRequestLogger
{
    private static ?array $context = null;
    private static bool $registered = false;

    public static function begin(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function (): void {
            self::flush();
        });
    }

    public static function identify(?int $credentialId, ?int $applicationId): void
    {
        self::$context = [
            'credential_id' => $credentialId,
            'application_id' => $applicationId,
        ];
    }

    private static function flush(): void
    {
        $context = self::$context ?? ['credential_id' => null, 'application_id' => null];
        $path = substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 190);
        $status = http_response_code();
        if (!is_int($status) || $status < 100) {
            $status = 200;
        }

        try {
            q(
                'INSERT INTO api_request_logs (credential_id, application_id, method, path, status_code, ip)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $context['credential_id'],
                    $context['application_id'],
                    substr(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), 0, 8),
                    $path,
                    $status,
                    request_ip(),
                ]
            );
        } catch (\Throwable $e) {
            log_exception($e);
        }
    }
}
