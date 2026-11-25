<?php
declare(strict_types=1);

/**
 * Nightly database backup.
 *
 *   php bin/campuspay-backup.php
 *
 * Dumps schema + data to a gzipped SQL file under storage/backups, prunes dumps
 * older than the retention window, and — when BACKUP_UPLOAD_COMMAND is set —
 * hands the file to an off-host copy command. Run it from cron, separately from
 * the minute-by-minute upkeep cron.
 *
 * Options:  --keep=<days>  --dir=<path>  --no-upload
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

$options = getopt('', ['keep::', 'dir::', 'no-upload']);

$dir = (string) ($options['dir'] ?? env('BACKUP_DIR', BASE_PATH . '/storage/backups'));
$keep = isset($options['keep']) && is_numeric($options['keep'])
    ? max(1, (int) $options['keep'])
    : max(1, (int) env('BACKUP_RETENTION_DAYS', '14'));

if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create backup directory: $dir\n");
    exit(1);
}

$name = (string) env('DB_NAME', '');
$user = (string) env('DB_USER', '');
if ($name === '' || $user === '') {
    fwrite(STDERR, "DB_NAME and DB_USER must be set in .env\n");
    exit(1);
}

$file = rtrim($dir, '/\\') . '/campuspay-' . gmdate('Ymd-His') . '.sql.gz';

// Password goes through the environment (MYSQL_PWD), never the command line,
// so it does not show up in the process list.
putenv('MYSQL_PWD=' . (string) env('DB_PASS', ''));

$command = escapeshellarg((string) env('BACKUP_MYSQLDUMP', 'mysqldump'))
    . ' --single-transaction --routines --triggers --quick'
    . ' --host=' . escapeshellarg((string) env('DB_HOST', '127.0.0.1'))
    . ' --port=' . escapeshellarg((string) env('DB_PORT', '3306'))
    . ' --user=' . escapeshellarg($user)
    . ' ' . escapeshellarg($name)
    . ' | gzip -9 > ' . escapeshellarg($file);

echo '[' . gmdate('c') . "] CampusPay backup\n";
echo "target=$file\n";

$return = 0;
system($command, $return);

if ($return !== 0 || !is_file($file) || filesize($file) === 0) {
    @unlink($file);
    fwrite(STDERR, "mysqldump failed (exit $return). Check the binary path, credentials and gzip.\n");
    exit(1);
}
printf("written=%s (%.1f KB)\n", $file, filesize($file) / 1024);

// Retention: drop dumps older than the window.
$cutoff = time() - $keep * 86400;
$removed = 0;
foreach (glob(rtrim($dir, '/\\') . '/campuspay-*.sql.gz') ?: [] as $old) {
    if (is_file($old) && filemtime($old) < $cutoff) {
        @unlink($old);
        $removed++;
    }
}
echo "pruned=$removed (keeping {$keep} days)\n";

// Off-host copy: a shell command with {file} replaced by the dump path.
$upload = isset($options['no-upload']) ? '' : (string) env('BACKUP_UPLOAD_COMMAND', '');
if ($upload !== '') {
    $rc = 0;
    system(str_replace('{file}', escapeshellarg($file), $upload), $rc);
    echo $rc === 0 ? "upload=ok\n" : "upload=failed (exit $rc)\n";
}

echo "done\n";
