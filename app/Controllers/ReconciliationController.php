<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Auth;
use App\Core\View;
use App\Services\Daraja;
use App\Services\MaintenanceService;
use App\Services\MpesaService;

final class ReconciliationController
{
    private const PER_PAGE = 25;

    public function index(): void
    {
        Auth::requireAdmin();

        $where = ['1 = 1'];
        $params = [];
        $result = query_value('result');
        if (in_array($result, ['match', 'mismatch'], true)) {
            $where[] = 'r.matches = ?';
            $params[] = $result === 'match' ? 1 : 0;
        }
        $search = query_value('q');
        if ($search !== '') {
            $where[] = '(pr.reference LIKE ? OR r.details LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) q(
            "SELECT COUNT(*) FROM reconciliation_records r
             LEFT JOIN payment_requests pr ON pr.id = r.payment_request_id
             WHERE $whereSql",
            $params
        )->fetchColumn();
        $pager = paginate($total, self::PER_PAGE, (int) query_value('page', '1'));

        $records = q(
            "SELECT r.*, pr.reference, pr.amount, pr.status AS request_status,
                    t.status AS transaction_status, a.name AS application_name
             FROM reconciliation_records r
             LEFT JOIN payment_requests pr ON pr.id = r.payment_request_id
             LEFT JOIN transactions t ON t.id = r.transaction_id
             LEFT JOIN applications a ON a.id = pr.application_id
             WHERE $whereSql
             ORDER BY r.id DESC
             LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        )->fetchAll();

        $openAttempts = q(
            "SELECT pa.id, pa.status, pa.checkout_request_id, pa.created_at,
                    pr.reference, pr.status AS request_status, pr.last_status_check_at,
                    t.status AS transaction_status
             FROM payment_attempts pa
             JOIN payment_requests pr ON pr.id = pa.payment_request_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             WHERE pa.status = 'accepted' AND pa.checkout_request_id IS NOT NULL
               AND t.status = 'pending'
             ORDER BY pa.id DESC LIMIT 10"
        )->fetchAll();

        $metrics = [
            'checks_today' => (int) q('SELECT COUNT(*) FROM reconciliation_records WHERE checked_at >= UTC_DATE()')->fetchColumn(),
            'mismatches_7d' => (int) q('SELECT COUNT(*) FROM reconciliation_records WHERE matches = 0 AND checked_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)')->fetchColumn(),
            'awaiting' => (int) q(
                "SELECT COUNT(*) FROM payment_attempts pa
                 JOIN payment_requests pr ON pr.id = pa.payment_request_id
                 JOIN transactions t ON t.payment_request_id = pr.id
                 WHERE pa.status = 'accepted' AND pa.checkout_request_id IS NOT NULL AND t.status = 'pending'"
            )->fetchColumn(),
            'last_check' => q('SELECT MAX(checked_at) FROM reconciliation_records')->fetchColumn(),
        ];

        View::render('admin/reconciliation/index', [
            'title' => 'Reconciliation',
            'activeNav' => 'reconciliation',
            'records' => $records,
            'openAttempts' => $openAttempts,
            'metrics' => $metrics,
            'filters' => ['q' => $search, 'result' => $result],
            'pager' => $pager,
            'darajaConfigured' => Daraja::configured(),
        ], 'layouts/admin');
    }

    public function run(): void
    {
        Auth::requireAdmin();
        \App\Core\Csrf::requirePost();

        $adminId = Auth::admin()['id'] ?? null;
        $stats = MaintenanceService::reconcileOpenAttempts(5, $adminId === null ? null : (int) $adminId);

        if (!$stats['configured']) {
            flash('warning', 'Daraja is not configured, so no status query was sent. Open attempts stay open.');
        } else {
            flash('info', sprintf(
                'Checked %d attempt(s): %d confirmed, %d failed, %d still processing, %d mismatch(es), %d error(s).',
                $stats['checked'],
                $stats['confirmed'],
                $stats['failed'],
                $stats['pending'],
                $stats['mismatches'],
                $stats['errors']
            ));
        }

        redirect('/admin/reconciliation');
    }

    public function check(string $id): void
    {
        Auth::requireAdmin();
        \App\Core\Csrf::requirePost();

        try {
            $adminId = Auth::admin()['id'] ?? null;
            $result = MpesaService::checkAttempt((int) $id, 'manual', $adminId === null ? null : (int) $adminId);
            flash($result['matches'] ? 'success' : 'warning', sprintf(
                'M-Pesa answered: %s. Local record: %s.',
                $result['provider_status'],
                $result['outcome']
            ));
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
        } catch (\Throwable $e) {
            log_exception($e);
            flash('danger', 'The status check failed unexpectedly. Nothing was changed.');
        }

        redirect('/admin/reconciliation');
    }
}
