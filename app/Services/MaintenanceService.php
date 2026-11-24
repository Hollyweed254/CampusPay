<?php
declare(strict_types=1);

namespace App\Services;

/** Scheduled upkeep: close checkout windows and compare open attempts with M-Pesa. */
final class MaintenanceService
{
    public static function expireCheckouts(int $limit = 500): int
    {
        $limit = max(1, min(2000, $limit));
        $rows = q(
            "SELECT reference FROM payment_requests
             WHERE status IN ('pending','processing')
               AND expires_at IS NOT NULL
               AND expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at ASC
             LIMIT $limit"
        )->fetchAll();

        $expired = 0;
        foreach ($rows as $row) {
            $request = CheckoutService::load((string) $row['reference']);
            if ($request === null) {
                continue;
            }
            if (CheckoutService::expireIfNeeded($request)['status'] === 'expired') {
                $expired++;
            }
        }
        return $expired;
    }

    /**
     * Query M-Pesa for attempts that have had time to settle, oldest check
     * first. Each answer becomes a reconciliation record; successful ones are
     * applied through the same guarded path as callbacks.
     *
     * @return array{configured:bool, checked:int, confirmed:int, failed:int, pending:int, mismatches:int, errors:int}
     */
    public static function reconcileOpenAttempts(int $limit = 25, ?int $adminId = null): array
    {
        $stats = ['configured' => Daraja::configured(), 'checked' => 0, 'confirmed' => 0, 'failed' => 0, 'pending' => 0, 'mismatches' => 0, 'errors' => 0];
        if (!$stats['configured']) {
            return $stats;
        }

        $limit = max(1, min(100, $limit));
        $staleMinutes = max(1, Setting::int('reconciliation_stale_after_minutes', 2));
        $cooldown = max(5, Setting::int('status_check_cooldown_seconds', 20));

        $ids = q(
            "SELECT pa.id
             FROM payment_attempts pa
             JOIN payment_requests pr ON pr.id = pa.payment_request_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             WHERE pa.checkout_request_id IS NOT NULL
               AND pa.status IN ('accepted','timed_out')
               AND pa.created_at <= (UTC_TIMESTAMP() - INTERVAL $staleMinutes MINUTE)
               AND pa.created_at >= (UTC_TIMESTAMP() - INTERVAL 3 DAY)
               AND (t.status = 'pending' OR (pa.status = 'timed_out' AND t.status = 'expired'))
               AND (pr.last_status_check_at IS NULL
                    OR pr.last_status_check_at <= (UTC_TIMESTAMP() - INTERVAL $cooldown SECOND))
             ORDER BY COALESCE(pr.last_status_check_at, pa.created_at) ASC
             LIMIT $limit"
        )->fetchAll();

        foreach ($ids as $row) {
            $stats['checked']++;
            try {
                $result = MpesaService::checkAttempt((int) $row['id'], 'reconciliation', $adminId);
                if (!$result['matches']) {
                    $stats['mismatches']++;
                }
                if ($result['pending']) {
                    $stats['pending']++;
                } elseif ($result['outcome'] === 'confirmed' || $result['outcome'] === 'receipt_attached') {
                    $stats['confirmed']++;
                } elseif ($result['outcome'] === 'failed') {
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                log_exception($e);
            }
        }

        return $stats;
    }
}
