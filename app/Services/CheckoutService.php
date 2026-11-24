<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\Database;

final class CheckoutService
{
    public static function load(string $reference): ?array
    {
        $row = q(
            "SELECT pr.*,
                    a.name AS application_name, a.slug AS application_slug,
                    a.cash_on_meeting_enabled, a.settlement_model, a.status AS application_status,
                    t.id AS transaction_id, t.status AS transaction_status, t.provider_receipt,
                    t.verified_at, t.created_at AS transaction_created_at,
                    (SELECT pa.response_description FROM payment_attempts pa
                      WHERE pa.payment_request_id = pr.id ORDER BY pa.id DESC LIMIT 1) AS last_provider_message
             FROM payment_requests pr
             JOIN applications a ON a.id = pr.application_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             WHERE pr.reference = ? LIMIT 1",
            [$reference]
        )->fetch();
        return $row === false ? null : $row;
    }

    /** Expire a request whose checkout window has passed; returns the fresh row. */
    public static function expireIfNeeded(array $request): array
    {
        if (!in_array($request['status'], ['pending', 'processing'], true)
            || $request['expires_at'] === null
            || strtotime($request['expires_at'] . ' UTC') > time()) {
            return $request;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $changed = q(
                "UPDATE payment_requests SET status = 'expired' WHERE id = ? AND status IN ('pending','processing')",
                [(int) $request['id']]
            )->rowCount();
            q(
                "UPDATE transactions SET status = 'expired' WHERE payment_request_id = ? AND status = 'pending'",
                [(int) $request['id']]
            );
            q(
                "UPDATE payment_attempts SET status = 'timed_out'
                 WHERE payment_request_id = ? AND status IN ('initiated','accepted')",
                [(int) $request['id']]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($changed > 0) {
            AuditLogger::log(
                'payment_request.expired',
                'payment_request',
                (string) $request['reference'],
                ['application' => $request['application_slug']],
                'system',
                null
            );
        }

        return self::load((string) $request['reference']) ?? $request;
    }

    public static function initiateMpesa(array $request, string $phoneRaw): void
    {
        if ($request['payment_method'] !== 'mpesa') {
            throw new AppException('wrong_method', 'This request is a cash-on-meeting record, not an M-Pesa payment.', 409);
        }
        if (!in_array($request['status'], ['pending', 'processing', 'failed'], true)) {
            throw new AppException('request_closed', 'This payment request is no longer open.', 409);
        }
        if ($request['expires_at'] !== null && strtotime($request['expires_at'] . ' UTC') <= time()) {
            throw new AppException('request_expired', 'This payment request has expired.', 410);
        }

        $phone = PhoneNumber::normalize($phoneRaw);
        if ($phone === null) {
            throw new AppException('invalid_phone', 'Enter a valid Kenyan mobile number, for example 0712 345 678.');
        }

        $cents = (int) round(((float) $request['amount']) * 100);
        if ($cents % 100 !== 0) {
            throw new AppException(
                'unsupported_amount',
                'M-Pesa can only charge whole shillings. Ask the merchant for a new request.',
                422
            );
        }

        $active = q(
            "SELECT status FROM payment_attempts
             WHERE payment_request_id = ? AND status IN ('initiated','accepted')
               AND created_at > (UTC_TIMESTAMP() - INTERVAL 2 MINUTE)
             ORDER BY id DESC LIMIT 1",
            [(int) $request['id']]
        )->fetch();
        if ($active !== false) {
            throw new AppException(
                'payment_in_progress',
                $active['status'] === 'initiated'
                    ? 'The last prompt could not be confirmed as delivered. Wait a moment and check the status before trying again.'
                    : 'A payment prompt was already sent. Check your phone before trying again.',
                409
            );
        }

        if (!Daraja::configured()) {
            throw new AppException(
                'provider_not_configured',
                'M-Pesa is not configured on this deployment. The request stays open; contact the merchant.',
                503
            );
        }

        $pdo = Database::pdo();
        $pdo->prepare("INSERT INTO payment_attempts (payment_request_id, status) VALUES (?, 'initiated')")
            ->execute([(int) $request['id']]);
        $attemptId = (int) $pdo->lastInsertId();

        try {
            $accepted = Daraja::stkPush($request, $phone);
        } catch (AppException $e) {
            self::recordPushFailure($attemptId, $request, $e);
            throw $e;
        } catch (\Throwable $e) {
            log_exception($e);
            self::recordPushFailure($attemptId, $request, null);
            throw new AppException(
                'provider_timeout',
                'M-Pesa did not answer in time. The prompt may still arrive — check the status before trying again.',
                504
            );
        }

        q(
            "UPDATE payment_attempts
             SET status = 'accepted', checkout_request_id = ?, merchant_request_id = ?,
                 response_code = ?, response_description = ?
             WHERE id = ?",
            [
                $accepted['checkout_request_id'],
                $accepted['merchant_request_id'],
                $accepted['response_code'],
                $accepted['response_description'],
                $attemptId,
            ]
        );
        q(
            "INSERT INTO transactions (payment_request_id, provider, provider_reference, amount, currency, status)
             VALUES (?, 'mpesa', ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
               provider_reference = IF(status = 'pending', VALUES(provider_reference), provider_reference),
               status = IF(status IN ('failed','cancelled','expired'), 'pending', status)",
            [(int) $request['id'], $accepted['checkout_request_id'], $request['amount'], $request['currency']]
        );
        q(
            "UPDATE payment_requests
             SET status = 'processing', buyer_phone = ?, last_status_check_at = UTC_TIMESTAMP()
             WHERE id = ? AND status IN ('pending','processing','failed')",
            [$phone, (int) $request['id']]
        );

        AuditLogger::log('mpesa.stk_accepted', 'payment_request', (string) $request['reference'], [
            'attempt_id' => $attemptId,
            'checkout_request_id' => $accepted['checkout_request_id'],
            'phone' => mask_phone($phone),
        ]);
    }

    /**
     * Ask M-Pesa for the latest status when the buyer's page polls. Rate
     * limited by last_status_check_at; failures stay invisible to the buyer
     * and are retried on the next poll.
     */
    public static function syncStatus(array $request): array
    {
        if ((string) $request['status'] !== 'processing'
            || $request['payment_method'] !== 'mpesa'
            || !Daraja::configured()) {
            return $request;
        }

        $cooldown = max(5, Setting::int('status_check_cooldown_seconds', 20));
        if ($request['last_status_check_at'] !== null
            && strtotime($request['last_status_check_at'] . ' UTC') > time() - $cooldown) {
            return $request;
        }

        $attempt = q(
            "SELECT id FROM payment_attempts
             WHERE payment_request_id = ? AND status = 'accepted' AND checkout_request_id IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [(int) $request['id']]
        )->fetch();
        if ($attempt === false) {
            return $request;
        }

        try {
            MpesaService::checkAttempt((int) $attempt['id'], 'checkout');
        } catch (\Throwable $e) {
            log_exception($e);
        }

        return self::load((string) $request['reference']) ?? $request;
    }

    /** Mark a push that never got a usable answer; an unknown outcome stays open. */
    private static function recordPushFailure(int $attemptId, array $request, ?AppException $error): void
    {
        $definitive = $error !== null && in_array($error->publicCode, ['provider_rejected', 'provider_auth_failed', 'provider_not_configured', 'unsupported_amount'], true);

        if ($definitive) {
            q(
                "UPDATE payment_attempts
                 SET status = 'failed', response_code = ?, response_description = ?, error_message = ?
                 WHERE id = ?",
                [
                    (string) ($error->details['response_code'] ?? ''),
                    (string) ($error->details['provider_message'] ?? $error->getMessage()),
                    $error->getMessage(),
                    $attemptId,
                ]
            );
        } else {
            q(
                "UPDATE payment_attempts SET error_message = ? WHERE id = ?",
                ['Outcome unknown: no usable answer from M-Pesa. Verify before sending another prompt.', $attemptId]
            );
        }

        AuditLogger::log('mpesa.stk_failed', 'payment_request', (string) $request['reference'], [
            'attempt_id' => $attemptId,
            'code' => $error?->publicCode ?? 'provider_timeout',
            'definitive' => $definitive,
        ]);
    }

    public static function statusPayload(array $request): array
    {
        [$label] = status_meta((string) $request['status']);
        $polling = in_array($request['status'], ['pending', 'processing'], true);

        return [
            'reference' => $request['reference'],
            'status' => $request['status'],
            'label' => $label,
            'receipt' => $request['provider_receipt'] ?? null,
            'message' => self::message($request),
            'expires_at' => $request['expires_at'],
            'poll' => $polling,
            'updated_at' => $request['updated_at'] ?? null,
        ];
    }

    public static function message(array $request): string
    {
        return match ($request['status']) {
            'pending' => $request['payment_method'] === 'cash_on_meeting'
                ? 'Cash on meeting recorded. Pay the seller in person.'
                : 'Waiting for the payment to start.',
            'processing' => 'Check your phone and approve the M-Pesa prompt.',
            'succeeded' => 'Payment confirmed by M-Pesa.'
                . (!empty($request['provider_receipt']) ? ' Receipt ' . $request['provider_receipt'] . '.' : ''),
            'recorded' => 'Cash on meeting recorded. CampusPay did not process any money.',
            'failed' => self::failedMessage($request),
            'cancelled' => 'This payment request was cancelled.',
            'expired' => 'This payment request expired before it was paid.',
            default => 'Payment status: ' . $request['status'] . '.',
        };
    }

    private static function failedMessage(array $request): string
    {
        $detail = trim((string) ($request['last_provider_message'] ?? ''));
        if ($detail === '') {
            return 'The payment did not go through. You can try again while this link is open.';
        }
        if (preg_match('/[.!?]$/', $detail) !== 1) {
            $detail .= '.';
        }
        return 'The payment did not go through. ' . $detail;
    }
}
