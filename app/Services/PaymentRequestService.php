<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\Database;
use PDOException;

final class PaymentRequestService
{
    /**
     * Create a payment request (M-Pesa) or a record-only cash agreement.
     *
     * @param array $input description, amount, payment_method, buyer_name,
     *                     buyer_phone, expires_minutes, idempotency_key, metadata
     * @return array{request: array, created: bool}
     */
    public static function create(
        array $input,
        array $application,
        string $via = 'api',
        string $actorType = 'system',
        ?int $actorId = null
    ): array {
        if ($application['status'] !== 'active') {
            throw new AppException('application_inactive', 'This application is not active.', 403);
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) < 3 || mb_strlen($description) > 255) {
            throw new AppException('invalid_description', 'Description must be between 3 and 255 characters.');
        }

        $amountRaw = trim((string) ($input['amount'] ?? ''));
        if (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountRaw) !== 1) {
            throw new AppException('invalid_amount', 'Amount must be a positive number with at most two decimals.');
        }
        $amount = number_format((float) $amountRaw, 2, '.', '');
        if ((float) $amount < 1) {
            throw new AppException('invalid_amount', 'The minimum amount is KSh 1.00.');
        }
        $max = (float) Setting::get('max_amount_kes', '250000');
        if ((float) $amount > $max) {
            throw new AppException('amount_too_large', 'The maximum amount is KSh ' . number_format($max, 2) . '.');
        }

        $method = (string) ($input['payment_method'] ?? 'mpesa');
        if (!in_array($method, ['mpesa', 'cash_on_meeting'], true)) {
            throw new AppException('invalid_method', 'Choose M-Pesa or cash on meeting.');
        }

        $buyerName = trim((string) ($input['buyer_name'] ?? ''));
        if (mb_strlen($buyerName) > 120) {
            throw new AppException('invalid_buyer_name', 'Buyer name must be at most 120 characters.');
        }

        $phone = null;
        if ($method === 'mpesa') {
            if ($application['settlement_model'] !== 'merchant_collection') {
                throw new AppException(
                    'settlement_not_configured',
                    'This application is not configured for M-Pesa collections. Set its settlement model first.',
                    409
                );
            }
            $phone = PhoneNumber::normalize((string) ($input['buyer_phone'] ?? ''));
            if ($phone === null) {
                throw new AppException('invalid_phone', 'Enter a valid Kenyan mobile number, for example 0712 345 678.');
            }
        } else {
            if ((int) $application['cash_on_meeting_enabled'] !== 1) {
                throw new AppException('cash_not_enabled', 'Cash on meeting is not enabled for this application.');
            }
            if ($application['settlement_model'] === 'undecided') {
                throw new AppException('settlement_not_configured', 'Choose a settlement model for this application first.', 409);
            }
        }

        $ttl = Setting::int('checkout_ttl_minutes', 30);
        if (isset($input['expires_minutes']) && is_numeric($input['expires_minutes'])) {
            $ttl = (int) $input['expires_minutes'];
        }
        $ttl = max(5, min(1440, $ttl));

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new AppException('invalid_idempotency_key', 'Idempotency keys must be at most 64 characters.');
        }
        $idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;

        if ($idempotencyKey !== null) {
            $existing = q(
                'SELECT * FROM payment_requests WHERE application_id = ? AND idempotency_key = ? LIMIT 1',
                [(int) $application['id'], $idempotencyKey]
            )->fetch();
            if ($existing !== false) {
                return ['request' => $existing, 'created' => false];
            }
        }

        $metadata = $input['metadata'] ?? null;
        $metadataJson = null;
        if (is_array($metadata)) {
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($metadata) && trim($metadata) !== '') {
            $metadataJson = trim($metadata);
        }
        if ($metadataJson !== null && strlen($metadataJson) > 4000) {
            throw new AppException('metadata_too_large', 'Metadata must be at most 4000 characters.');
        }

        $status = $method === 'cash_on_meeting' ? 'recorded' : 'pending';
        $expiresAt = $method === 'cash_on_meeting' ? null : gmdate('Y-m-d H:i:s', time() + $ttl * 60);
        $pdo = Database::pdo();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = Reference::payment();
            $pdo->beginTransaction();
            try {
                q(
                    'INSERT INTO payment_requests
                       (reference, application_id, description, amount, currency, buyer_name, buyer_phone,
                        payment_method, status, idempotency_key, expires_at, metadata_json, created_via)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $reference,
                        (int) $application['id'],
                        $description,
                        $amount,
                        'KES',
                        $buyerName === '' ? null : $buyerName,
                        $phone,
                        $method,
                        $status,
                        $idempotencyKey,
                        $expiresAt,
                        $metadataJson,
                        $via,
                    ]
                );
                $requestId = (int) $pdo->lastInsertId();

                if ($method === 'cash_on_meeting') {
                    q(
                        "INSERT INTO transactions (payment_request_id, provider, amount, currency, status)
                         VALUES (?, 'cash_on_meeting', ?, 'KES', 'reported')",
                        [$requestId, $amount]
                    );
                }

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                $sqlState = $e->errorInfo[1] ?? 0;
                if ($sqlState === 1062 && $idempotencyKey !== null
                    && str_contains($e->getMessage(), 'uq_payment_requests_idem')) {
                    $existing = q(
                        'SELECT * FROM payment_requests WHERE application_id = ? AND idempotency_key = ? LIMIT 1',
                        [(int) $application['id'], $idempotencyKey]
                    )->fetch();
                    if ($existing !== false) {
                        return ['request' => $existing, 'created' => false];
                    }
                }
                if ($sqlState === 1062 && str_contains($e->getMessage(), 'uq_payment_requests_reference')) {
                    continue;
                }
                throw $e;
            }

            $request = q('SELECT * FROM payment_requests WHERE id = ? LIMIT 1', [$requestId])->fetch();
            AuditLogger::log(
                'payment_request.created',
                'payment_request',
                $reference,
                [
                    'application' => $application['slug'],
                    'method' => $method,
                    'amount' => $amount,
                    'via' => $via,
                ],
                $actorType,
                $actorId
            );
            return ['request' => $request, 'created' => true];
        }

        throw new AppException('reference_generation_failed', 'Could not allocate a payment reference. Try again.', 500);
    }

    /** Cancel a request that has not settled. Provider attempts are kept as history. */
    public static function cancel(string $reference, string $actorType = 'administrator', ?int $actorId = null): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $locked = q(
                'SELECT id, status FROM payment_requests WHERE reference = ? FOR UPDATE',
                [$reference]
            )->fetch();
            if ($locked === false) {
                throw new AppException('not_found', 'Payment request not found.', 404);
            }
            if (!in_array($locked['status'], ['pending', 'processing'], true)) {
                throw new AppException('not_cancellable', 'Only pending or processing requests can be cancelled.', 409);
            }
            q("UPDATE payment_requests SET status = 'cancelled' WHERE id = ?", [(int) $locked['id']]);
            q(
                "UPDATE transactions SET status = 'cancelled' WHERE payment_request_id = ? AND status = 'pending'",
                [(int) $locked['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log('payment_request.cancelled', 'payment_request', $reference, [], $actorType, $actorId);
    }
}
