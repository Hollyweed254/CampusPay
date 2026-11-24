<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Services\MaintenanceService;
use App\Services\WebhookService;

$options = getopt('', ['limit::', 'webhook-limit::', 'skip-expire', 'skip-reconcile', 'skip-webhooks']);
$limit = isset($options['limit']) && is_numeric($options['limit']) ? (int) $options['limit'] : 25;
$webhookLimit = isset($options['webhook-limit']) && is_numeric($options['webhook-limit'])
    ? (int) $options['webhook-limit']
    : 20;

$started = microtime(true);
echo '[' . gmdate('c') . "] CampusPay upkeep\n";

if (!isset($options['skip-expire'])) {
    $expired = MaintenanceService::expireCheckouts();
    echo "expired_requests={$expired}\n";
}

if (!isset($options['skip-reconcile'])) {
    $stats = MaintenanceService::reconcileOpenAttempts($limit);
    if (!$stats['configured']) {
        echo "reconcile=skipped (Daraja not configured)\n";
    } else {
        printf(
            "checked=%d confirmed=%d failed=%d pending=%d mismatches=%d errors=%d\n",
            $stats['checked'],
            $stats['confirmed'],
            $stats['failed'],
            $stats['pending'],
            $stats['mismatches'],
            $stats['errors']
        );
    }
}

if (!isset($options['skip-webhooks'])) {
    $hooks = WebhookService::runDue($webhookLimit);
    printf(
        "webhooks_checked=%d delivered=%d failed=%d exhausted=%d\n",
        $hooks['checked'],
        $hooks['delivered'],
        $hooks['failed'],
        $hooks['exhausted']
    );
}

printf("done_in=%.2fs\n", microtime(true) - $started);
\App\Services\Setting::set('last_cron_run_at', gmdate('Y-m-d H:i:s'));
