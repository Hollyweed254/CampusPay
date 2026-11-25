<?php
declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    return App\Core\Env::get($key, $default);
}

function q(string $sql, array $params = []): PDOStatement
{
    $statement = App\Core\Database::pdo()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    $configured = env('APP_BASE_PATH');
    if ($configured !== null && trim($configured, '/') !== '') {
        return '/' . trim($configured, '/');
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    return ($dir === '' || $dir === '.' || $dir === '/') ? '' : $dir;
}

function url(string $path = '/'): string
{
    $base = app_base_path();
    $path = '/' . ltrim($path, '/');
    return $base . ($path === '/' ? '/' : $path);
}

function absolute_url(string $path = '/'): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . url($path);
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 302);
    exit;
}

/** Build a URL that keeps current filters and overrides a few of them. */
function filter_url(string $path, array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    $params = array_filter($params, static fn ($v): bool => $v !== '' && $v !== null && $v !== false);
    return url($path) . ($params === [] ? '' : '?' . http_build_query($params));
}

function flash(string $type, string $message, string $scope = 'admin'): void
{
    $_SESSION['flash'][$scope][] = ['type' => $type, 'message' => $message];
}

function flashes(string $scope = 'admin'): array
{
    $items = $_SESSION['flash'][$scope] ?? [];
    unset($_SESSION['flash'][$scope]);
    return is_array($items) ? $items : [];
}

function request_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function post_value(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query_value(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function money_ksh(float|int|string $amount): string
{
    return 'KSh ' . number_format((float) $amount, 2);
}

/** Label + chip modifier for every status used by requests and transactions. */
function status_meta(string $status): array
{
    return match ($status) {
        'succeeded', 'provider_confirmed' => ['Successful', 'cp-chip--ok'],
        'processing' => ['Processing', 'cp-chip--warn'],
        'pending' => ['Pending', 'cp-chip--warn'],
        'reported' => ['Recorded', 'cp-chip--info'],
        'failed' => ['Failed', 'cp-chip--bad'],
        'cancelled' => ['Cancelled', 'cp-chip--muted'],
        'expired' => ['Expired', 'cp-chip--muted'],
        'disputed' => ['Disputed', 'cp-chip--bad'],
        'open' => ['Open', 'cp-chip--warn'],
        'under_review' => ['Under review', 'cp-chip--info'],
        'resolved' => ['Resolved', 'cp-chip--ok'],
        'rejected' => ['Rejected', 'cp-chip--muted'],
        'active' => ['Active', 'cp-chip--ok'],
        'disabled', 'suspended' => ['Disabled', 'cp-chip--muted'],
        'delivered' => ['Delivered', 'cp-chip--ok'],
        'exhausted' => ['Exhausted', 'cp-chip--bad'],
        default => [ucfirst(str_replace('_', ' ', $status)), 'cp-chip--muted'],
    };
}

function status_chip(string $status): string
{
    [$label, $modifier] = status_meta($status);
    return '<span class="cp-chip ' . $modifier . '">' . e($label) . '</span>';
}

function payment_method_label(string $method): string
{
    return $method === 'cash_on_meeting' ? 'Cash on meeting' : 'M-Pesa';
}

/** Human label + masked form for a Kenyan MSISDN (2547XXXXXXXX). */
function mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($digits) < 9) {
        return '—';
    }
    return '••• ' . substr($digits, -4);
}

/**
 * Simple offset pagination metadata; page 1-based, clamped to the last page.
 * $total is the row count for the same filters.
 */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; font-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https && env('APP_ENV') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function log_exception(Throwable $e): void
{
    $line = sprintf("[%s] %s: %s in %s:%d\n%s\n", gmdate('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    @file_put_contents(BASE_PATH . '/storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);

    try {
        App\Services\ErrorReporter::alert($e::class . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    } catch (Throwable $ignored) {
        // Alerting must never mask the original failure.
    }
}
