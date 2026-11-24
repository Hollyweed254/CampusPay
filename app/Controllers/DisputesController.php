<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\DisputeService;

final class DisputesController
{
    private const STATUSES = ['open', 'under_review', 'resolved', 'rejected'];
    private const PER_PAGE = 20;

    public function index(): void
    {
        Auth::requireAdmin();

        $where = [];
        $params = [];

        $status = query_value('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }
        $search = query_value('q');
        if ($search !== '') {
            $where[] = '(pr.reference LIKE ? OR d.reason LIKE ? OR d.resolution LIKE ?)';
            array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
        }
        $application = (int) query_value('app', '0');
        if ($application > 0) {
            $where[] = 'pr.application_id = ?';
            $params[] = $application;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int) q(
            "SELECT COUNT(*)
             FROM disputes d
             JOIN transactions t ON t.id = d.transaction_id
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             $whereSql",
            $params
        )->fetchColumn();
        $pager = paginate($total, self::PER_PAGE, (int) query_value('page', '1'));

        $disputes = q(
            "SELECT d.id, d.status, d.reason, d.resolution, d.opened_by_type, d.created_at, d.resolved_at,
                    t.id AS transaction_id, t.status AS transaction_status, t.amount, t.provider, t.provider_receipt,
                    pr.reference, pr.description, a.name AS application_name
             FROM disputes d
             JOIN transactions t ON t.id = d.transaction_id
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             $whereSql
             ORDER BY d.id DESC
             LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        )->fetchAll();

        $metrics = [
            'active' => (int) q("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn(),
            'awaiting_review' => (int) q("SELECT COUNT(*) FROM disputes WHERE status = 'open'")->fetchColumn(),
            'resolved_7d' => (int) q("SELECT COUNT(*) FROM disputes WHERE status = 'resolved' AND resolved_at >= (UTC_DATE() - INTERVAL 7 DAY)")->fetchColumn(),
            'oldest_active' => q("SELECT MIN(created_at) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn() ?: null,
        ];

        View::render('admin/disputes/index', [
            'title' => 'Disputes',
            'activeNav' => 'disputes',
            'disputes' => $disputes,
            'applications' => q('SELECT id, name FROM applications ORDER BY name ASC')->fetchAll(),
            'metrics' => $metrics,
            'statuses' => self::STATUSES,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'app' => $application > 0 ? (string) $application : '',
            ],
            'pager' => $pager,
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $transaction = $this->loadTransaction((int) query_value('transaction', '0'));
        if ($transaction === false) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }
        $active = q(
            "SELECT id FROM disputes WHERE transaction_id = ? AND status IN ('open','under_review') LIMIT 1",
            [(int) $transaction['id']]
        )->fetchColumn();
        if ($active !== false) {
            flash('info', 'This transaction already has an open dispute.');
            redirect('/admin/disputes/' . (int) $active);
        }
        if (!in_array($transaction['status'], ['provider_confirmed', 'reported', 'failed'], true)) {
            flash('warning', 'Only confirmed, cash-recorded or failed transactions can be disputed.');
            redirect('/admin/transactions/' . (int) $transaction['id']);
        }

        View::render('admin/disputes/create', [
            'title' => 'Open dispute',
            'activeNav' => 'transactions',
            'transaction' => $transaction,
        ], 'layouts/admin');
    }

    public function show(string $id): void
    {
        Auth::requireAdmin();

        $dispute = q(
            'SELECT d.*, t.provider, t.provider_receipt, t.provider_reference, t.amount, t.currency,
                    t.status AS transaction_status, t.verified_at, t.id AS tx_id,
                    pr.reference, pr.description, pr.status AS request_status, pr.buyer_name, pr.buyer_phone,
                    a.name AS application_name
             FROM disputes d
             JOIN transactions t ON t.id = d.transaction_id
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             WHERE d.id = ? LIMIT 1',
            [(int) $id]
        )->fetch();
        if ($dispute === false) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }

        $audit = q(
            "SELECT action, actor_type, actor_id, ip, created_at FROM audit_logs
             WHERE (target_type = 'dispute' AND target_id = ?)
                OR (target_type = 'transaction' AND target_id = ?)
                OR (target_type = 'payment_request' AND target_id = ?)
             ORDER BY id DESC LIMIT 40",
            [(string) $dispute['id'], (string) $dispute['tx_id'], $dispute['reference']]
        )->fetchAll();

        View::render('admin/disputes/show', [
            'title' => 'Dispute #' . $dispute['id'],
            'activeNav' => 'disputes',
            'dispute' => $dispute,
            'audit' => $audit,
        ], 'layouts/admin');
    }

    public function open(string $transactionId): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            $reason = (string) post_value('reason');
            $adminId = (int) (Auth::admin()['id'] ?? 0);
            $disputeId = DisputeService::open((int) $transactionId, $reason, $adminId);
            flash('success', 'Dispute opened. The transaction is now marked disputed until it is resolved.');
            redirect('/admin/disputes/' . $disputeId);
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
            redirect('/admin/disputes/new?transaction=' . (int) $transactionId);
        }
    }

    public function review(string $id): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            DisputeService::startReview((int) $id, (int) (Auth::admin()['id'] ?? 0));
            flash('info', 'Dispute moved to review.');
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('/admin/disputes/' . (int) $id);
    }

    public function resolve(string $id): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            DisputeService::resolve(
                (int) $id,
                (string) post_value('outcome'),
                (string) post_value('resolution'),
                (int) (Auth::admin()['id'] ?? 0)
            );
            flash('success', 'Dispute resolved. The transaction and request now record the corrected outcome.');
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('/admin/disputes/' . (int) $id);
    }

    public function reject(string $id): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            DisputeService::reject((int) $id, (string) post_value('resolution'), (int) (Auth::admin()['id'] ?? 0));
            flash('success', 'Dispute rejected. The transaction was restored to its prior status.');
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('/admin/disputes/' . (int) $id);
    }

    /** @return array|false */
    private function loadTransaction(int $id): array|false
    {
        return q(
            'SELECT t.id, t.status, t.provider, t.provider_receipt, t.amount, t.currency,
                    pr.reference, pr.description, pr.buyer_name, pr.buyer_phone, a.name AS application_name
             FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             WHERE t.id = ? LIMIT 1',
            [$id]
        )->fetch();
    }
}
