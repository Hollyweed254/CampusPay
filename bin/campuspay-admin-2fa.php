<?php
declare(strict_types=1);

/**
 * Reset an administrator's two-factor authentication.
 *
 * Use this to recover an account whose authenticator app or phone is lost:
 * it clears the stored TOTP seed so the administrator can sign in with the
 * password alone and enrol a new device.
 *
 *   php bin/campuspay-admin-2fa.php --email=admin@example.com --status
 *   php bin/campuspay-admin-2fa.php --email=admin@example.com --disable
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Repositories\AdminRepository;

$args = getopt('', ['email:', 'disable', 'status']);

$email = strtolower(trim((string) ($args['email'] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/campuspay-admin-2fa.php --email=<address> [--status|--disable]\n");
    exit(1);
}

$admin = AdminRepository::findByEmail($email);
if ($admin === null) {
    fwrite(STDERR, "No administrator found for $email\n");
    exit(1);
}

$enabled = $admin['totp_enabled_at'] !== null;
echo "Administrator: {$admin['email']}\n";
echo 'Two-factor:    ' . ($enabled ? 'enabled since ' . $admin['totp_enabled_at'] . ' UTC' : 'not enabled') . "\n";

if (isset($args['status'])) {
    exit(0);
}

if (!isset($args['disable'])) {
    fwrite(STDERR, "Nothing to do. Pass --disable to reset two-factor, or --status to check it.\n");
    exit(1);
}

if (!$enabled) {
    echo "Two-factor is already off. Nothing to reset.\n";
    exit(0);
}

AdminRepository::disableTotp((int) $admin['id']);
echo "Two-factor authentication reset. The administrator can now sign in with the password and enrol again.\n";
