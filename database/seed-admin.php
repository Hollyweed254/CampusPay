<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

$args = getopt('', ['email::', 'password::', 'name::']);

$email = strtolower(trim((string) ($args['email'] ?? env('ADMIN_EMAIL', 'admin@campuspay.local'))));
$name = trim((string) ($args['name'] ?? 'Administrator'));
$password = (string) ($args['password'] ?? env('ADMIN_PASSWORD', ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email: $email\n");
    exit(1);
}
if ($password === '') {
    $password = bin2hex(random_bytes(8)) . '!K7a';
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$existing = q('SELECT id FROM administrators WHERE email = ? LIMIT 1', [$email])->fetchColumn();
if ($existing) {
    echo "Administrator $email already exists. Nothing to do.\n";
    exit(0);
}

q(
    'INSERT INTO administrators (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
    [$name, $email, password_hash($password, PASSWORD_DEFAULT), 'superadmin']
);

$file = BASE_PATH . '/storage/dev/admin-credentials.txt';
file_put_contents($file, "email: $email\npassword: $password\ncreated_utc: " . gmdate('c') . "\n");

echo "Administrator created: $email\n";
echo "Password: $password\n";
echo "Saved to storage/dev/admin-credentials.txt — change before any real deployment.\n";
