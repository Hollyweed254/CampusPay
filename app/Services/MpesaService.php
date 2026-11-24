<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\Database;

/**
 * Applies M-Pesa results to CampusPay records.
 *
 * Only a callback authenticated by the callback token or an STK Push Query
 * answer can confirm money. Browser input, admin edits and webhooks cannot.
 * Every apply path is idempotent and serialised with row locks: a retried
 * callback can never double-confirm, and a late failure can never downgrade a
 * confirmed payment. A late success, however, overrides failed/expired because
 * the provider's receipt proves the money moved.
 */
final class MpesaService
{
    /** @return array{status:string, outcome:string, reference:?string} */
    public static function receiveCallback(array $payload): array
    {
        $stk = $payload['Body']['stkCallback'] ?? null;
        if (!is_array($stk)) {
            throw new AppException('invalid_callback', 'Callback envelope is missing Body.stkCallback.', 400);
        }

        $checkoutId = trim((string) ($stk['CheckoutRequestID'] ?? ''));
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new AppException('invalid_callback', 'Callback payload is not encodable JSON.', 400);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            "INSERT INTO provider_callbacks (provider, provider_reference, payload, signature_valid, processed)
             VALUES ('mpesa', ?, ?, 1, 0)"
        )->execute([$checkoutId !== '' ? $checkoutId : null, $payloadJson]);
        $callbackId = (int) $pdo->lastInsertId();

        $outcome = 'unmatched';
        $reference = null;

        $attempt = q(
            "SELECT pa.*, pr.reference, pr.amount, pr.status AS request_status, pr.payment_method
             FROM payment_attempts pa
             JOIN payment_requests pr ON pr.id = pa.payment_request_id
             WHERE pa.checkout_request_id = ? ORDER BY pa.id DESC LIMIT 1",
            [$checkoutId]
        )->fetch();

        if ($attempt === false) {
            AuditLogger::log('mpesa.callback_unmatched', 'provider_callback', (string) $callbackId, [
                'checkout_request_id' => self::shortText($checkoutId, 64),
            ], 'system');
        } else {
            $reference = (string) $attempt['reference'];
            $merchantId = trim((string) ($stk['MerchantRequestID'] ?? ''));
            if ($merchantId !== '' && $attempt['merchant_request_id'] !== null
                && !hash_equals((string) $attempt['merchant_request_id'], $merchantId)) {
                $outcome = 'merchant_mismatch';
                AuditLogger::log('mpesa.callback_conflict', 'payment_request', $reference, [
                    'reason' => 'merchant_request_id_mismatch',
                ], 'system');
            } else {
                $resultCode = is_numeric($stk['ResultCode'] ?? null) ? (int) $stk['ResultCode'] : null;
                $resultDesc = self::shortText((string) ($stk['ResultDesc'] ?? ''), 255);

                if ($resultCode === null) {
                    $outcome = 'malformed';
                    AuditLogger::log('mpesa.callback_conflict', 'payment_request', $reference, [
                        'reason' => 'missing_result_code',
                    ], 'system');
                } elseif ($resultCode === 0) {
                    $metadata = self::metadata($stk['CallbackMetadata']['Item'] ?? []);
                    $receipt = self::receipt($metadata['MpesaReceiptNumber'] ?? null);
                    $amount = self::amount($metadata['Amount'] ?? null);
                    if ($receipt === null) {
                        $outcome = self::rejectConfirmation($attempt, 'success_without_receipt', null, 'callback');
                    } else {
                        $outcome = self::confirm(
                            $attempt,
                            $receipt,
                            $amount,
                            'callback',
                            true,
                            self::shortText((string) ($metadata['TransactionDate'] ?? ''), 14)
                        );
                    }
                } else {
                    $outcome = self::fail($attempt, $resultCode, $resultDesc, 'callback');
                }
            }
        }

        q(
            'UPDATE provider_callbacks SET processed = 1, processed_at = UTC_TIMESTAMP() WHERE id = ?',
            [$callbackId]
        );
        AuditLogger::log('mpesa.callback_received', 'payment_request', $reference, [
            'callback_id' => $callbackId,
            'checkout_request_id' => self::shortText($checkoutId, 64),
            'result_code' => isset($stk['ResultCode']) ? self::shortText((string) $stk['ResultCode'], 16) : null,
            'outcome' => $outcome,
        ], 'system');

        return ['status' => 'accepted', 'outcome' => $outcome, 'reference' => $reference];
    }

    /**
     * Query the provider for one attempt, store the comparison, apply it.
     *
     * @return array{outcome:string, pending:bool, matches:bool, provider_status:string, result_code:?int, result_desc:string}
     */
    public static function checkAttempt(int $attemptId, string $source, ?int $adminId = null): array
    {
        $attempt = q(
            "SELECT pa.*, pr.reference, pr.amount, pr.status AS request_status, pr.id AS request_id,
                    t.id AS transaction_id, t.status AS transaction_status
             FROM payment_attempts pa
             JOIN payment_requests pr ON pr.id = pa.payment_request_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             WHERE pa.id = ? LIMIT 1",
            [$attemptId]
        )->fetch();

        if ($attempt === false) {
            throw new AppException('attempt_not_found', 'Payment attempt not found.', 404);
        }
        if (trim((string) ($attempt['checkout_request_id'] ?? '')) === '') {
            throw new AppException('attempt_not_queryable', 'This attempt has no CheckoutRequestID, so M-Pesa cannot be queried for it.', 409);
        }

        try {
            $result = Daraja::query((string) $attempt['checkout_request_id']);
        } finally {
            q('UPDATE payment_requests SET last_status_check_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $attempt['request_id']]);
        }

        $applied = self::applyQueryResult($attempt, $result, $source);

        if ($adminId !== null) {
            AuditLogger::log('mpesa.manual_check', 'payment_request', (string) $attempt['reference'], array_merge($applied, [
                'attempt_id' => $attemptId,
                'source' => $source,
            ]), 'administrator', $adminId);
        }

        return $applied;
    }

    /**
     * Provider-confirmed success. $requireAmount is true for callbacks (the
     * metadata carries TransAmount) and false for queries (they do not).
     */
    private static function confirm(
        array $attempt,
        ?string $receipt,
        ?float $amount,
        string $source,
        bool $requireAmount,
        ?string $providerTimestamp = null
    ): string {
        if ($attempt['payment_method'] !== 'mpesa') {
            AuditLogger::log('mpesa.callback_conflict', 'payment_request', (string) $attempt['reference'], [
                'reason' => 'not_an_mpesa_request',
            ], 'system');
            return 'conflict';
        }
        if ($requireAmount && $amount === null) {
            return self::rejectConfirmation($attempt, 'success_without_amount', $receipt, $source);
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $request = q('SELECT * FROM payment_requests WHERE id = ? FOR UPDATE', [(int) $attempt['payment_request_id']])->fetch();
            if ($request === false) {
                $pdo->rollBack();
                return 'unmatched';
            }

            $transaction = q('SELECT * FROM transactions WHERE payment_request_id = ? FOR UPDATE', [(int) $request['id']])->fetch();
            if ($transaction === false) {
                q(
                    "INSERT INTO transactions (payment_request_id, provider, provider_reference, amount, currency, status)
                     VALUES (?, 'mpesa', ?, ?, ?, 'pending')",
                    [(int) $request['id'], (string) $attempt['checkout_request_id'], $request['amount'], $request['currency']]
                );
                $transaction = q('SELECT * FROM transactions WHERE payment_request_id = ? LIMIT 1', [(int) $request['id']])->fetch();
            }

            $existingStatus = (string) $transaction['status'];
            if ($existingStatus === 'provider_confirmed') {
                if ($receipt !== null && $transaction['provider_receipt'] === null) {
                    q(
                        'UPDATE transactions SET provider_receipt = ?, verified_at = UTC_TIMESTAMP() WHERE id = ?',
                        [$receipt, (int) $transaction['id']]
                    );
                    $pdo->commit();
                    AuditLogger::log('mpesa.receipt_attached', 'payment_request', (string) $request['reference'], [
                        'receipt' => $receipt,
                        'source' => $source,
                    ], 'system');
                    return 'receipt_attached';
                }
                $pdo->rollBack();
                if ($receipt !== null && $transaction['provider_receipt'] !== null
                    && !hash_equals((string) $transaction['provider_receipt'], $receipt)) {
                    AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $request['reference'], [
                        'reason' => 'different_receipt',
                        'recorded_receipt' => (string) $transaction['provider_receipt'],
                        'incoming_receipt' => $receipt,
                    ], 'system');
                    return 'conflict';
                }
                return 'already';
            }

            if (in_array($existingStatus, ['disputed', 'reported'], true)) {
                $pdo->rollBack();
                AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $request['reference'], [
                    'reason' => 'transaction_status_' . $existingStatus,
                ], 'system');
                return 'conflict';
            }

            if ($amount === null) {
                $unresolved = q(
                    "SELECT id FROM reconciliation_records
                     WHERE payment_request_id = ? AND matches = 0
                       AND provider_status IN ('success_amount_mismatch','success_without_amount')
                     LIMIT 1",
                    [(int) $request['id']]
                )->fetchColumn();
                if ($unresolved !== false) {
                    $pdo->rollBack();
                    AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $request['reference'], [
                        'reason' => 'unresolved_amount_mismatch',
                        'source' => $source,
                    ], 'system');
                    return 'conflict';
                }
            }

            if ($amount !== null) {
                $expected = (int) round(((float) $request['amount']) * 100);
                $actual = (int) round($amount * 100);
                if ($expected !== $actual) {
                    $pdo->rollBack();
                    self::recordReconciliation((int) $request['id'], (int) $transaction['id'], 'success_amount_mismatch', false,
                        'Expected ' . money_ksh($request['amount']) . ' but M-Pesa reported ' . money_ksh($amount) . '.');
                    AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $request['reference'], [
                        'reason' => 'amount_mismatch',
                        'expected' => (string) $request['amount'],
                        'reported' => number_format($amount, 2, '.', ''),
                        'source' => $source,
                    ], 'system');
                    return 'amount_mismatch';
                }
            }

            if ($receipt !== null) {
                $other = q('SELECT id FROM transactions WHERE provider_receipt = ? AND id <> ? LIMIT 1', [$receipt, (int) $transaction['id']])->fetchColumn();
                if ($other !== false) {
                    $pdo->rollBack();
                    AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $request['reference'], [
                        'reason' => 'receipt_used_elsewhere',
                        'receipt' => $receipt,
                    ], 'system');
                    return 'conflict';
                }
            }

            q(
                "UPDATE transactions
                 SET status = 'provider_confirmed',
                     provider_receipt = COALESCE(?, provider_receipt),
                     provider_reference = COALESCE(NULLIF(provider_reference, ''), ?),
                     verified_at = UTC_TIMESTAMP()
                 WHERE id = ?",
                [$receipt, (string) $attempt['checkout_request_id'], (int) $transaction['id']]
            );
            q(
                "UPDATE payment_requests SET status = 'succeeded', last_status_check_at = UTC_TIMESTAMP() WHERE id = ?",
                [(int) $request['id']]
            );
            q(
                "UPDATE payment_attempts SET status = 'accepted', response_code = '0',
                        response_description = 'Payment confirmed by M-Pesa.', error_message = NULL
                 WHERE id = ?",
                [(int) $attempt['id']]
            );
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e->errorInfo[1] ?? 0) === 1062) {
                AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $attempt['reference'], [
                    'reason' => 'duplicate_receipt_race',
                    'receipt' => $receipt,
                ], 'system');
                return 'conflict';
            }
            throw $e;
        }

        self::recordReconciliation((int) $request['id'], (int) $transaction['id'], 'success', true,
            $source === 'callback'
                ? 'Callback ResultCode 0' . ($receipt !== null ? ' with receipt ' . $receipt : '') . '.'
                : 'STK query returned ResultCode 0.');

        AuditLogger::log('mpesa.payment_confirmed', 'payment_request', (string) $request['reference'], [
            'receipt' => $receipt,
            'amount' => (string) $request['amount'],
            'source' => $source,
            'provider_transaction_date' => $providerTimestamp,
        ], 'system');

        try {
            WebhookService::enqueueForRequest((int) $request['id'], 'payment.confirmed', (int) $transaction['id']);
        } catch (\Throwable $e) {
            log_exception($e);
        }

        return 'confirmed';
    }

    /** @return string confirmed|failed|already|ignored|conflict */
    private static function fail(array $attempt, int $resultCode, string $resultDesc, string $source): string
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $request = q('SELECT * FROM payment_requests WHERE id = ? FOR UPDATE', [(int) $attempt['payment_request_id']])->fetch();
            if ($request === false) {
                $pdo->rollBack();
                return 'unmatched';
            }
            $transaction = q('SELECT * FROM transactions WHERE payment_request_id = ? FOR UPDATE', [(int) $request['id']])->fetch();

            $txStatus = $transaction === false ? 'pending' : (string) $transaction['status'];
            if ($txStatus === 'provider_confirmed' || in_array((string) $request['status'], ['succeeded', 'recorded'], true)) {
                $pdo->rollBack();
                AuditLogger::log('mpesa.callback_conflict', 'payment_request', (string) $request['reference'], [
                    'reason' => 'late_failure_after_confirmation',
                    'result_code' => $resultCode,
                ], 'system');
                return 'ignored';
            }
            if (in_array($txStatus, ['disputed', 'reported'], true)) {
                $pdo->rollBack();
                return 'conflict';
            }
            if ($txStatus === 'failed' && (string) $request['status'] === 'failed') {
                $pdo->rollBack();
                return 'already';
            }

            if ($transaction !== false) {
                q("UPDATE transactions SET status = 'failed' WHERE id = ?", [(int) $transaction['id']]);
            }
            q("UPDATE payment_requests SET status = 'failed' WHERE id = ?", [(int) $request['id']]);
            q(
                "UPDATE payment_attempts SET status = 'failed', response_code = ?, response_description = ?, error_message = NULL
                 WHERE id = ?",
                [self::shortText((string) $resultCode, 16), $resultDesc, (int) $attempt['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($transaction !== false) {
            self::recordReconciliation((int) $request['id'], (int) $transaction['id'], 'failed', true,
                'Provider result code ' . $resultCode . ': ' . $resultDesc);
        }

        AuditLogger::log('mpesa.payment_failed', 'payment_request', (string) $request['reference'], [
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'source' => $source,
        ], 'system');

        try {
            WebhookService::enqueueForRequest(
                (int) $request['id'],
                'payment.failed',
                $transaction !== false ? (int) $transaction['id'] : null
            );
        } catch (\Throwable $e) {
            log_exception($e);
        }

        return 'failed';
    }

    /** @param array{pending:bool, result_code:?int, result_desc:string} $result */
    private static function applyQueryResult(array $attempt, array $result, string $source): array
    {
        if ($result['pending']) {
            $matches = ($attempt['transaction_status'] ?? null) === 'pending';
            self::recordReconciliation(
                (int) $attempt['request_id'],
                $attempt['transaction_id'] !== null ? (int) $attempt['transaction_id'] : null,
                'processing',
                $matches,
                'M-Pesa says the transaction is still being processed.'
            );
            return [
                'outcome' => 'pending',
                'pending' => true,
                'matches' => $matches,
                'provider_status' => 'processing',
                'result_code' => null,
                'result_desc' => $result['result_desc'],
            ];
        }

        if ((int) $result['result_code'] === 0) {
            $outcome = self::confirm($attempt, null, null, $source, false);
            $matches = in_array($outcome, ['confirmed', 'already', 'receipt_attached'], true);
            if (!$matches) {
                self::recordReconciliation(
                    (int) $attempt['request_id'],
                    $attempt['transaction_id'] !== null ? (int) $attempt['transaction_id'] : null,
                    'success',
                    false,
                    'M-Pesa reported success but the local record was not confirmed (' . $outcome . ').'
                );
            }
            return [
                'outcome' => $outcome,
                'pending' => false,
                'matches' => $matches,
                'provider_status' => 'success',
                'result_code' => 0,
                'result_desc' => $result['result_desc'],
            ];
        }

        $outcome = self::fail($attempt, (int) $result['result_code'], $result['result_desc'], $source);
        $matches = in_array($outcome, ['failed', 'already'], true);
        if (!$matches) {
            self::recordReconciliation(
                (int) $attempt['request_id'],
                $attempt['transaction_id'] !== null ? (int) $attempt['transaction_id'] : null,
                'failed',
                false,
                'M-Pesa reported failure but the local record was kept (' . $outcome . ').'
            );
        }
        return [
            'outcome' => $outcome,
            'pending' => false,
            'matches' => $matches,
            'provider_status' => 'failed',
            'result_code' => (int) $result['result_code'],
            'result_desc' => $result['result_desc'],
        ];
    }

    public static function recordReconciliation(
        int $requestId,
        ?int $transactionId,
        string $providerStatus,
        bool $matches,
        string $details
    ): void {
        q(
            "INSERT INTO reconciliation_records (payment_request_id, transaction_id, provider, provider_status, matches, details)
             VALUES (?, ?, 'mpesa', ?, ?, ?)",
            [$requestId, $transactionId, self::shortText($providerStatus, 40), $matches ? 1 : 0, self::shortText($details, 500)]
        );
    }

    private static function rejectConfirmation(array $attempt, string $reason, ?string $receipt, string $source): string
    {
        $providerStatus = $reason === 'success_without_amount' ? 'success_without_amount' : 'success_without_receipt';
        self::recordReconciliation(
            (int) $attempt['payment_request_id'],
            null,
            $providerStatus,
            false,
            $reason === 'success_without_amount'
                ? 'M-Pesa reported success but did not include a usable amount; the transaction was not confirmed.'
                : 'M-Pesa reported success but did not include a valid receipt; the transaction was not confirmed.'
        );
        AuditLogger::log('mpesa.confirmation_conflict', 'payment_request', (string) $attempt['reference'], [
            'reason' => $reason,
            'receipt' => $receipt,
            'source' => $source,
        ], 'system');
        return $reason === 'success_without_amount' ? 'amount_missing' : 'receipt_missing';
    }

    /** @return array<string, mixed> */
    private static function metadata(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['Name'])) {
                $out[(string) $item['Name']] = $item['Value'] ?? null;
            }
        }
        return $out;
    }

    private static function receipt(mixed $value): ?string
    {
        $receipt = strtoupper(trim((string) $value));
        return preg_match('/^[A-Z0-9]{6,20}$/', $receipt) === 1 ? $receipt : null;
    }

    private static function amount(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function shortText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }
}
