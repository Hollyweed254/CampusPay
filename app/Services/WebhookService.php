<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\HttpClient;

/**
 * Signed webhook deliveries.
 *
 * Events are snapshotted into webhook_deliveries at the moment a payment is
 * confirmed or fails, then delivered by the cron runner. Each delivery is
 * HMAC-SHA256 signed with the application's newest active API secret:
 *
 *   X-CP-Signature: hex(HMAC-SHA256("<timestamp>.<raw body>", secret))
 *
 * Delivery is at-least-once; receivers must deduplicate on the event id.
 */
final class WebhookService
{
    private const RETRY_DELAYS = [30, 120, 600, 1800, 7200];

    /**
     * Queue webhook events for a payment request if the application has a
     * webhook endpoint and an active credential to sign with.
     */
    public static function enqueueForRequest(int $paymentRequestId, string $event, ?int $transactionId = null): void
    {
        $row = q(
            'SELECT pr.*, a.id AS app_id, a.name AS application_name, a.slug AS application_slug,
                    a.status AS application_status, a.webhook_url,
                    t.id AS tx_id, t.provider, t.provider_reference, t.provider_receipt,
                    t.amount AS tx_amount, t.currency AS tx_currency, t.status AS tx_status, t.verified_at
             FROM payment_requests pr
             JOIN applications a ON a.id = pr.application_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             WHERE pr.id = ? LIMIT 1',
            [$paymentRequestId]
        )->fetch();

        if ($row === false || trim((string) $row['webhook_url']) === '') {
            return;
        }
        if ($row['application_status'] !== 'active') {
            return;
        }
        $hasCredential = q(
            "SELECT 1 FROM api_credentials WHERE application_id = ? AND status = 'active' LIMIT 1",
            [(int) $row['app_id']]
        )->fetchColumn();
        if ($hasCredential === false) {
            return;
        }

        $payload = [
            'id' => 'evt_' . bin2hex(random_bytes(12)),
            'type' => $event,
            'createdAt' => gmdate('c'),
            'application' => [
                'slug' => $row['application_slug'],
                'name' => $row['application_name'],
            ],
            'paymentRequest' => [
                'reference' => $row['reference'],
                'description' => $row['description'],
                'amount' => number_format((float) $row['amount'], 2, '.', ''),
                'currency' => $row['currency'],
                'paymentMethod' => $row['payment_method'],
                'status' => $row['status'],
                'buyerName' => $row['buyer_name'],
                'buyerPhone' => $row['buyer_phone'],
                'checkoutUrl' => absolute_url('/checkout/' . $row['reference']),
                'expiresAt' => $row['expires_at'],
                'createdAt' => $row['created_at'],
            ],
            'transaction' => $row['tx_id'] === null ? null : [
                'provider' => $row['provider'],
                'providerReference' => $row['provider_reference'],
                'receipt' => $row['provider_receipt'],
                'amount' => number_format((float) $row['tx_amount'], 2, '.', ''),
                'currency' => $row['tx_currency'],
                'status' => $row['tx_status'],
                'confirmedAt' => $row['tx_status'] === 'provider_confirmed' ? $row['verified_at'] : null,
            ],
        ];

        q(
            "INSERT INTO webhook_deliveries (application_id, transaction_id, event, payload, status, next_retry_at)
             VALUES (?, ?, ?, ?, 'pending', UTC_TIMESTAMP())",
            [
                (int) $row['app_id'],
                $row['tx_id'] !== null ? (int) $row['tx_id'] : $transactionId,
                $event,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** @return array{checked:int, delivered:int, failed:int, exhausted:int} */
    public static function runDue(int $limit = 20): array
    {
        $deliveries = q(
            "SELECT id FROM webhook_deliveries
             WHERE status IN ('pending','failed')
               AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
             ORDER BY id ASC LIMIT $limit"
        )->fetchAll();

        $stats = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'exhausted' => 0];
        foreach ($deliveries as $delivery) {
            $stats['checked']++;
            $outcome = self::deliver((int) $delivery['id']);
            if (isset($stats[$outcome])) {
                $stats[$outcome]++;
            }
        }
        return $stats;
    }

    /** Reset and deliver one event immediately from the admin console. */
    public static function resend(int $deliveryId, ?int $adminId = null): string
    {
        $exists = q('SELECT id FROM webhook_deliveries WHERE id = ? LIMIT 1', [$deliveryId])->fetchColumn();
        if ($exists === false) {
            throw new AppException('not_found', 'Webhook delivery not found.', 404);
        }
        q(
            "UPDATE webhook_deliveries SET status = 'pending', attempts = 0, next_retry_at = UTC_TIMESTAMP(),
                    last_error = NULL, last_response_code = NULL
             WHERE id = ?",
            [$deliveryId]
        );
        $outcome = self::deliver($deliveryId);
        AuditLogger::log('webhook.resent', 'webhook_delivery', (string) $deliveryId, [
            'outcome' => $outcome,
        ], 'administrator', $adminId);
        return $outcome;
    }

    /** @return string delivered|failed|exhausted */
    private static function deliver(int $deliveryId): string
    {
        $delivery = q(
            'SELECT wd.*, a.webhook_url, a.name AS application_name, a.status AS application_status
             FROM webhook_deliveries wd
             JOIN applications a ON a.id = wd.application_id
             WHERE wd.id = ? LIMIT 1',
            [$deliveryId]
        )->fetch();

        if ($delivery === false) {
            return 'failed';
        }

        $markFailure = static function (string $error, ?int $code) use ($delivery): string {
            $attempts = (int) $delivery['attempts'] + 1;
            $max = max(1, Setting::int('webhook_max_attempts', 5));
            $exhausted = $attempts >= $max;
            $delay = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];

            q(
                'UPDATE webhook_deliveries
                 SET status = ?, attempts = ?, last_response_code = ?, last_error = ?,
                     next_retry_at = ' . ($exhausted ? 'NULL' : 'UTC_TIMESTAMP() + INTERVAL ' . (int) $delay . ' SECOND') . '
                 WHERE id = ?',
                [$exhausted ? 'exhausted' : 'failed', $attempts, $code, mb_substr($error, 0, 500), (int) $delivery['id']]
            );

            AuditLogger::log('webhook.failed', 'webhook_delivery', (string) $delivery['id'], [
                'event' => $delivery['event'],
                'application' => $delivery['application_name'],
                'attempt' => $attempts,
                'http_status' => $code,
                'error' => mb_substr($error, 0, 200),
            ], 'system');

            return $exhausted ? 'exhausted' : 'failed';
        };

        $url = trim((string) $delivery['webhook_url']);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array((string) parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $markFailure('The webhook endpoint URL is missing or invalid.', null);
        }
        if (Daraja::environment() === 'production' && (string) parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return $markFailure('Webhook endpoints must use HTTPS in production.', null);
        }

        $credential = q(
            "SELECT id, secret_cipher FROM api_credentials
             WHERE application_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [(int) $delivery['application_id']]
        )->fetch();
        if ($credential === false) {
            return $markFailure('No active API credential is available to sign the webhook.', null);
        }

        $body = json_encode(
            json_decode((string) $delivery['payload'], true),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($body === false) {
            return $markFailure('Stored webhook payload is unreadable.', null);
        }

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, SecretBox::decrypt((string) $credential['secret_cipher']));
        $eventId = (string) (json_decode($body, true)['id'] ?? '');

        $response = HttpClient::request('POST', $url, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: CampusPay-Webhooks/1.0',
            'X-CP-Event: ' . $delivery['event'],
            'X-CP-Event-Id: ' . $eventId,
            'X-CP-Delivery: ' . $delivery['id'],
            'X-CP-Timestamp: ' . $timestamp,
            'X-CP-Signature: ' . $signature,
        ], $body, 10);

        if ($response['error'] !== null) {
            return $markFailure('Transport error: ' . $response['error'], null);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $markFailure('Endpoint answered HTTP ' . $response['status'] . '.', $response['status']);
        }

        q(
            "UPDATE webhook_deliveries
             SET status = 'delivered', attempts = attempts + 1, last_response_code = ?,
                 last_error = NULL, next_retry_at = NULL, delivered_at = UTC_TIMESTAMP()
             WHERE id = ?",
            [$response['status'], (int) $delivery['id']]
        );

        AuditLogger::log('webhook.delivered', 'webhook_delivery', (string) $delivery['id'], [
            'event' => $delivery['event'],
            'application' => $delivery['application_name'],
            'http_status' => $response['status'],
        ], 'system');

        return 'delivered';
    }
}
