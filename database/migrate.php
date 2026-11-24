<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

$pdo = App\Core\Database::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$pending = 0;

foreach (glob(BASE_PATH . '/database/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "skip     $name\n";
        continue;
    }
    $sql = (string) file_get_contents($file);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $lines = array_filter(
            preg_split('/\r?\n/', $statement) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
    $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);
    echo "applied  $name\n";
    $pending++;
}

echo $pending === 0 ? "Already up to date.\n" : "Done: $pending migration(s) applied.\n";
