<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiRequestLogger;
use App\Core\AppException;
use App\Services\ApiAuth;
use App\Services\PaymentRequestService;

final class ApiController
{
    private const STATUSES = ['pending', 'processing', 'recorded', 'succeeded', 'failed', 'cancelled', 'expired'];
    private const MAX_PAGE_SIZE = 100;

    public function health(): void
    {
        json_out([
            'status' => 'ok',
            'service' => env('APP_NAME', 'CampusPay'),
            'time' => gmdate('c'),
        ]);
    }

    public function createPaymentRequest(): void
    {
        $this->withAuth(function (array $ctx): void {
            $body = $this->jsonBody();
            $application = $ctx['application'];

            $idempotencyKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            if ($idempotencyKey === '' && isset($body['idempotencyKey'])) {
                $idempotencyKey = trim((string) $body['idempotencyKey']);
            }
            if ($idempotencyKey !== '' && preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $idempotencyKey) !== 1) {
                throw new AppException(
                    'invalid_idempotency_key',
                    'Idempotency keys must be 8-64 characters of A-Z, a-z, 0-9, dot, underscore or hyphen.'
                );
            }

            $metadata = $body['metadata'] ?? null;
            if ($metadata !== null && !is_array($metadata)) {
                throw new AppException('invalid_metadata', 'metadata must be a JSON object.');
            }

            $result = PaymentRequestService::create(
                [
                    'description' => (string) ($body['description'] ?? ''),
                    'amount' => is_string($body['amount'] ?? null) || is_int($body['amount'] ?? null) || is_float($body['amount'] ?? null)
                        ? (string) $body['amount']
                        : '',
                    'payment_method' => (string) ($body['paymentMethod'] ?? 'mpesa'),
                    'buyer_name' => (string) ($body['buyerName'] ?? ''),
                    'buyer_phone' => (string) ($body['buyerPhone'] ?? ''),
                    'expires_minutes' => $body['expiresMinutes'] ?? null,
                    'idempotency_key' => $idempotencyKey === '' ? null : $idempotencyKey,
                    'metadata' => $metadata,
                ],
                $application,
                'api',
                'api',
                (int) $ctx['credential']['id']
            );

            $row = $this->loadRequest((string) $result['request']['reference'], (int) $application['id']);
            if ($row === null) {
                throw new AppException('not_found', 'Payment request not found after creation.', 500);
            }

            $status = $result['created'] ? 201 : 200;
            if (!$result['created']) {
                header('Idempotency-Replayed: true');
            }
            json_out([
                'ok' => true,
                'replayed' => !$result['created'],
                'paymentRequest' => $this->requestPayload($row),
            ], $status);
        });
    }

    public function listPaymentRequests(): void
    {
        $this->withAuth(function (array $ctx): void {
            $appId = (int) $ctx['application']['id'];
            $where = ['pr.application_id = ?'];
            $params = [$appId];

            $status = query_value('status');
            if ($status !== '') {
                if (!in_array($status, self::STATUSES, true)) {
                    throw new AppException('invalid_status_filter', 'Unknown status filter.');
                }
                $where[] = 'pr.status = ?';
                $params[] = $status;
            }

            $search = query_value('q');
            if ($search !== '') {
                $where[] = '(pr.reference LIKE ? OR pr.description LIKE ? OR pr.buyer_phone LIKE ?)';
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like);
            }

            $from = $this->dateValue(query_value('from'));
            if ($from !== null) {
                $where[] = 'pr.created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            $to = $this->dateValue(query_value('to'));
            if ($to !== null) {
                $where[] = 'pr.created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }

            $limit = (int) query_value('limit', '25');
            $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
            $offset = max(0, (int) query_value('offset', '0'));
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $total = (int) q("SELECT COUNT(*) FROM payment_requests pr $whereSql", $params)->fetchColumn();
            $rows = q(
                "SELECT pr.*, a.name AS application_name, a.slug AS application_slug,
                        t.id AS transaction_id, t.provider, t.provider_reference, t.provider_receipt,
                        t.status AS transaction_status, t.verified_at, t.created_at AS transaction_created_at
                 FROM payment_requests pr
                 JOIN applications a ON a.id = pr.application_id
                 LEFT JOIN transactions t ON t.payment_request_id = pr.id
                 $whereSql
                 ORDER BY pr.id DESC
                 LIMIT $limit OFFSET $offset",
                $params
            )->fetchAll();

            $items = array_map(function (array $row): array {
                if ($row['transaction_id'] !== null) {
                    $row['tx'] = [
                        'provider' => $row['provider'],
                        'provider_reference' => $row['provider_reference'],
                        'provider_receipt' => $row['provider_receipt'],
                        'amount' => $row['amount'],
                        'currency' => $row['currency'],
                        'status' => $row['transaction_status'],
                        'verified_at' => $row['verified_at'],
                        'created_at' => $row['transaction_created_at'],
                    ];
                }
                return $this->requestPayload($row);
            }, $rows);

            json_out([
                'ok' => true,
                'paymentRequests' => $items,
                'meta' => ['count' => count($items), 'total' => $total, 'limit' => $limit, 'offset' => $offset],
            ]);
        });
    }

    public function showPaymentRequest(string $reference): void
    {
        $this->withAuth(function (array $ctx) use ($reference): void {
            $row = $this->loadRequest($reference, (int) $ctx['application']['id']);
            if ($row === null) {
                throw new AppException('not_found', 'Payment request not found.', 404);
            }
            json_out(['ok' => true, 'paymentRequest' => $this->requestPayload($row)]);
        });
    }

    public function cancelPaymentRequest(string $reference): void
    {
        $this->withAuth(function (array $ctx) use ($reference): void {
            $row = $this->loadRequest($reference, (int) $ctx['application']['id']);
            if ($row === null) {
                throw new AppException('not_found', 'Payment request not found.', 404);
            }

            PaymentRequestService::cancel($reference, 'api', (int) $ctx['credential']['id']);

            $fresh = $this->loadRequest($reference, (int) $ctx['application']['id']);
            json_out([
                'ok' => true,
                'paymentRequest' => $this->requestPayload($fresh ?? $row),
            ]);
        });
    }

    public function showTransaction(string $reference): void
    {
        $this->withAuth(function (array $ctx) use ($reference): void {
            $row = $this->loadRequest($reference, (int) $ctx['application']['id']);
            if ($row === null || $row['transaction_id'] === null) {
                throw new AppException('not_found', 'Transaction not found for that payment request.', 404);
            }
            json_out([
                'ok' => true,
                'transaction' => $this->transactionPayload($row),
            ]);
        });
    }

    /** @return array<string, mixed>|null */
    private function loadRequest(string $reference, int $applicationId): ?array
    {
        $row = q(
            'SELECT pr.* FROM payment_requests pr
             WHERE pr.reference = ? AND pr.application_id = ? LIMIT 1',
            [$reference, $applicationId]
        )->fetch();
        if ($row === false) {
            return null;
        }

        $tx = q(
            'SELECT id, provider, provider_reference, provider_receipt, amount, currency, status, verified_at, created_at
             FROM transactions WHERE payment_request_id = ? LIMIT 1',
            [(int) $row['id']]
        )->fetch();
        $row['transaction_id'] = $tx === false ? null : (int) $tx['id'];
        $row['tx'] = $tx === false ? null : $tx;

        $row['attempts'] = q(
            'SELECT status, created_at FROM payment_attempts WHERE payment_request_id = ? ORDER BY id ASC',
            [(int) $row['id']]
        )->fetchAll();

        return $row;
    }

    private function requestPayload(array $row): array
    {
        $metadata = null;
        if (($row['metadata_json'] ?? null) !== null) {
            $decoded = json_decode((string) $row['metadata_json'], true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return [
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
            'metadata' => $metadata,
            'createdAt' => $row['created_at'],
            'transaction' => ($row['tx'] ?? null) !== null ? $this->transactionPayload($row) : null,
        ];
    }

    private function transactionPayload(array $row): array
    {
        $tx = $row['tx'];
        return [
            'paymentRequestReference' => $row['reference'],
            'provider' => $tx['provider'],
            'providerReference' => $tx['provider_reference'],
            'receipt' => $tx['provider_receipt'],
            'amount' => number_format((float) $tx['amount'], 2, '.', ''),
            'currency' => $tx['currency'],
            'status' => $tx['status'],
            'confirmedAt' => $tx['status'] === 'provider_confirmed' ? $tx['verified_at'] : null,
            'createdAt' => $tx['created_at'],
        ];
    }

    private function withAuth(callable $handler): void
    {
        try {
            $ctx = ApiAuth::authenticate();
            $handler($ctx);
        } catch (AppException $e) {
            if ($e->httpStatus === 401) {
                header('WWW-Authenticate: CampusPay-HMAC realm="CampusPay", key_header="X-CP-Key"');
                header('X-CP-Error: ' . $e->publicCode);
            }
            if ($e->publicCode === 'rate_limited') {
                header('Retry-After: 60');
            }
            $payload = ['ok' => false, 'error' => $e->publicCode, 'message' => $e->getMessage()];
            if ($e->details !== []) {
                $payload['details'] = $e->details;
            }
            json_out($payload, $e->httpStatus);
        } catch (\Throwable $e) {
            log_exception($e);
            json_out(['ok' => false, 'error' => 'server_error', 'message' => 'Unexpected server error. The request may be retried safely with the same idempotency key.'], 500);
        }
    }

    private function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AppException('invalid_json', 'Request body must be valid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new AppException('invalid_json', 'Request body must be a JSON object.');
        }
        return $data;
    }

    private function dateValue(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y) ? $value : null;
    }
}
