<?php
declare(strict_types=1);

/**
 * CampusFind webhook receiver.
 *
 * Deploy behind HTTPS and point the CampusPay integration's webhook URL here:
 *   https://campusfind.example/api/campuspay/webhook
 *
 * Environment:
 *   CAMPUSPAY_SECRET=cps_...
 *   CAMPUSFIND_DSN=mysql:host=127.0.0.1;dbname=campusfind;charset=utf8mb4
 *   CAMPUSFIND_DB_USER=...
 *   CAMPUSFIND_DB_PASS=...
 *
 * Schema (one-off):
 *
 *   CREATE TABLE campuspay_links (
 *     reference VARCHAR(40) NOT NULL PRIMARY KEY,
 *     payment_id INT UNSIGNED NOT NULL,
 *     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     CONSTRAINT fk_cp_links_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 *   CREATE TABLE campuspay_webhook_events (
 *     event_id VARCHAR(40) NOT NULL PRIMARY KEY,
 *     type VARCHAR(40) NOT NULL,
 *     payload JSON NOT NULL,
 *     received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

require __DIR__ . '/CampusPayClient.php';

header('Content-Type: application/json');

$raw = (string) file_get_contents('php://input');
$secret = (string) getenv('CAMPUSPAY_SECRET');

if ($secret === '' || !CampusPayClient::verifyWebhook($raw, $_SERVER, $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$event = json_decode($raw, true);
$eventId = is_array($event) ? (string) ($event['id'] ?? '') : '';
$type = is_array($event) ? (string) ($event['type'] ?? '') : '';
$reference = is_array($event) ? (string) ($event['paymentRequest']['reference'] ?? '') : '';

if ($eventId === '' || !in_array($type, ['payment.confirmed', 'payment.failed'], true) || $reference === '') {
    // A permanently malformed event should not be retried forever.
    http_response_code(202);
    echo json_encode(['ok' => true, 'ignored' => 'malformed']);
    exit;
}

try {
    $pdo = new PDO(
        (string) getenv('CAMPUSFIND_DSN'),
        (string) getenv('CAMPUSFIND_DB_USER'),
        (string) getenv('CAMPUSFIND_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO campuspay_webhook_events (event_id, type, payload) VALUES (?, ?, ?)'
    );
    $insert->execute([$eventId, $type, $raw]);

    if ($insert->rowCount() === 0) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'duplicate' => true]);
        exit;
    }

    $link = $pdo->prepare('SELECT payment_id FROM campuspay_links WHERE reference = ? LIMIT 1');
    $link->execute([$reference]);
    $paymentId = $link->fetchColumn();

    if ($paymentId !== false && $type === 'payment.confirmed') {
        $receipt = $event['transaction']['receipt'] ?? null;
        $pdo->prepare(
            "UPDATE payments
             SET status = 'paid', mpesa_receipt = COALESCE(?, mpesa_receipt),
                 result_code = '0', result_desc = 'Confirmed by CampusPay'
             WHERE id = ? AND status <> 'paid'"
        )->execute([$receipt, (int) $paymentId]);
    } elseif ($paymentId !== false) {
        $pdo->prepare(
            "UPDATE payments
             SET status = 'failed', result_code = NULL, result_desc = 'M-Pesa reported a failure'
             WHERE id = ? AND status NOT IN ('paid','failed')"
        )->execute([(int) $paymentId]);
    }

    // If no link exists, the event is still stored for manual review. A future
    // confirmation for the same reference must still be applied, so this is
    // not an error the sender should retry.
    $pdo->commit();

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('CampusPay webhook failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'processing_failed']);
}
