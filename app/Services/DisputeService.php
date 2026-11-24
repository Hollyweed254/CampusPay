<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\Database;

/**
 * Dispute lifecycle: open -> under_review -> resolved | rejected.
 *
 * Opening a dispute only changes the transaction status to "disputed"; the
 * previous status is kept so a rejected dispute can restore it exactly.
 * Resolving records the corrected outcome for both the transaction and, when
 * money moved, the payment request.
 */
final class DisputeService
{
    private const DISPUTABLE = ['provider_confirmed', 'reported', 'failed'];
    private const ACTIVE = ['open', 'under_review'];

    public static function open(int $transactionId, string $reason, ?int $adminId = null): int
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new AppException('invalid_reason', 'Explain the dispute in 5 to 500 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $transaction = q(
                'SELECT t.id, t.status, t.provider, pr.reference
                 FROM transactions t
                 JOIN payment_requests pr ON pr.id = t.payment_request_id
                 WHERE t.id = ? FOR UPDATE',
                [$transactionId]
            )->fetch();
            if ($transaction === false) {
                throw new AppException('not_found', 'Transaction not found.', 404);
            }
            $active = q(
                "SELECT id FROM disputes WHERE transaction_id = ? AND status IN ('open','under_review') LIMIT 1",
                [$transactionId]
            )->fetchColumn();
            if ($active !== false) {
                throw new AppException('already_disputed', 'This transaction already has an open dispute.', 409);
            }
            if (!in_array($transaction['status'], self::DISPUTABLE, true)) {
                throw new AppException(
                    'not_disputable',
                    'Only confirmed, cash-recorded or failed transactions can be disputed.',
                    409
                );
            }

            q(
                "INSERT INTO disputes (transaction_id, previous_transaction_status, opened_by_type, opened_by_id, reason, status)
                 VALUES (?, ?, 'administrator', ?, ?, 'open')",
                [$transactionId, $transaction['status'], $adminId, $reason]
            );
            $disputeId = (int) $pdo->lastInsertId();
            q("UPDATE transactions SET status = 'disputed' WHERE id = ?", [$transactionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log('dispute.opened', 'dispute', (string) $disputeId, [
            'transaction_id' => $transactionId,
            'reference' => $transaction['reference'],
            'previous_status' => $transaction['status'],
        ], 'administrator', $adminId);

        return $disputeId;
    }

    public static function startReview(int $disputeId, ?int $adminId = null): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $dispute = self::lockDispute($disputeId);
            if ($dispute['status'] !== 'open') {
                throw new AppException('invalid_transition', 'Only a newly opened dispute can start review.', 409);
            }
            q("UPDATE disputes SET status = 'under_review' WHERE id = ?", [$disputeId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log('dispute.review_started', 'dispute', (string) $disputeId, [
            'transaction_id' => (int) $dispute['transaction_id'],
            'reference' => $dispute['reference'],
        ], 'administrator', $adminId);
    }

    /**
     * Uphold or correct the record. M-Pesa transactions resolve to
     * payment_confirmed/payment_failed; cash records to record_upheld/payment_failed.
     */
    public static function resolve(int $disputeId, string $outcome, string $resolution, ?int $adminId = null): void
    {
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 5 || mb_strlen($resolution) > 500) {
            throw new AppException('invalid_resolution', 'Describe the outcome in 5 to 500 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $dispute = self::lockDispute($disputeId);
            if (!in_array($dispute['status'], self::ACTIVE, true)) {
                throw new AppException('invalid_transition', 'This dispute is already closed.', 409);
            }

            $allowed = $dispute['provider'] === 'cash_on_meeting'
                ? ['record_upheld', 'payment_failed']
                : ['payment_confirmed', 'payment_failed'];
            if (!in_array($outcome, $allowed, true)) {
                throw new AppException('invalid_outcome', 'Choose a valid resolution outcome for this transaction.', 422);
            }

            $transactionStatus = match ($outcome) {
                'payment_confirmed' => 'provider_confirmed',
                'record_upheld' => 'reported',
                default => 'failed',
            };
            $requestStatus = match ($outcome) {
                'payment_confirmed' => 'succeeded',
                'record_upheld' => 'recorded',
                default => 'failed',
            };

            q('UPDATE transactions SET status = ? WHERE id = ?', [$transactionStatus, (int) $dispute['transaction_id']]);
            q('UPDATE payment_requests SET status = ? WHERE id = ?', [$requestStatus, (int) $dispute['payment_request_id']]);
            q(
                "UPDATE disputes SET status = 'resolved', resolution = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?",
                [$resolution, $disputeId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log('dispute.resolved', 'dispute', (string) $disputeId, [
            'transaction_id' => (int) $dispute['transaction_id'],
            'reference' => $dispute['reference'],
            'outcome' => $outcome,
            'transaction_status' => $transactionStatus,
        ], 'administrator', $adminId);
    }

    /** Dismiss the dispute and restore the status the transaction had before it was opened. */
    public static function reject(int $disputeId, string $resolution, ?int $adminId = null): void
    {
        $resolution = trim($resolution);
        if (mb_strlen($resolution) < 5 || mb_strlen($resolution) > 500) {
            throw new AppException('invalid_resolution', 'Describe why the dispute is rejected in 5 to 500 characters.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $dispute = self::lockDispute($disputeId);
            if (!in_array($dispute['status'], self::ACTIVE, true)) {
                throw new AppException('invalid_transition', 'This dispute is already closed.', 409);
            }
            $restore = in_array($dispute['previous_transaction_status'], self::DISPUTABLE, true)
                ? $dispute['previous_transaction_status']
                : 'failed';
            q('UPDATE transactions SET status = ? WHERE id = ?', [$restore, (int) $dispute['transaction_id']]);
            q(
                "UPDATE disputes SET status = 'rejected', resolution = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?",
                [$resolution, $disputeId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log('dispute.rejected', 'dispute', (string) $disputeId, [
            'transaction_id' => (int) $dispute['transaction_id'],
            'reference' => $dispute['reference'],
            'restored_status' => $restore,
        ], 'administrator', $adminId);
    }

    /** @return array Locked dispute row joined with its transaction and request. */
    private static function lockDispute(int $disputeId): array
    {
        $dispute = q(
            'SELECT d.id, d.transaction_id, d.status, d.previous_transaction_status,
                    t.provider, t.payment_request_id, pr.reference
             FROM disputes d
             JOIN transactions t ON t.id = d.transaction_id
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             WHERE d.id = ? FOR UPDATE',
            [$disputeId]
        )->fetch();
        if ($dispute === false) {
            throw new AppException('not_found', 'Dispute not found.', 404);
        }
        return $dispute;
    }
}
