<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\AuditLogger;
use App\Services\PaymentRequestService;

final class PaymentRequestsController
{
    private const STATUSES = ['pending', 'processing', 'recorded', 'succeeded', 'failed', 'cancelled', 'expired'];
    private const PER_PAGE = 20;

    public function index(): void
    {
        Auth::requireAdmin();

        [$where, $params] = $this->filters();
        $total = (int) q("SELECT COUNT(*) FROM payment_requests pr $where", $params)->fetchColumn();
        $pager = paginate($total, self::PER_PAGE, (int) query_value('page', '1'));

        $rows = q(
            "SELECT pr.*, a.name AS application_name, t.provider_receipt, t.verified_at
             FROM payment_requests pr
             JOIN applications a ON a.id = pr.application_id
             LEFT JOIN transactions t ON t.payment_request_id = pr.id
             $where
             ORDER BY pr.id DESC
             LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        )->fetchAll();

        $applications = q('SELECT id, name FROM applications ORDER BY name ASC')->fetchAll();

        View::render('admin/payment-requests/index', [
            'title' => 'Payment Requests',
            'activeNav' => 'payment-requests',
            'requests' => $rows,
            'applications' => $applications,
            'statuses' => self::STATUSES,
            'filters' => $this->currentFilters(),
            'pager' => $pager,
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $applications = q("SELECT id, name, settlement_model, cash_on_meeting_enabled FROM applications WHERE status = 'active' ORDER BY name ASC")->fetchAll();

        View::render('admin/payment-requests/create', [
            'title' => 'New payment request',
            'activeNav' => 'payment-requests',
            'applications' => $applications,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        $application = q('SELECT * FROM applications WHERE id = ? LIMIT 1', [(int) post_value('application_id', '0')])->fetch();
        if ($application === false) {
            flash('danger', 'Choose an active application.');
            redirect('/admin/payment-requests/create');
        }

        try {
            $result = PaymentRequestService::create(
                [
                    'description' => post_value('description'),
                    'amount' => post_value('amount'),
                    'payment_method' => post_value('payment_method', 'mpesa'),
                    'buyer_name' => post_value('buyer_name'),
                    'buyer_phone' => post_value('buyer_phone'),
                    'expires_minutes' => post_value('expires_minutes'),
                    'metadata' => ['source' => 'admin'],
                ],
                $application,
                'admin',
                'administrator',
                Auth::admin()['id'] ?? null
            );
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
            redirect('/admin/payment-requests/create');
        }

        flash('success', 'Payment request ' . $result['request']['reference'] . ' created.');
        redirect('/admin/payment-requests/' . $result['request']['reference']);
    }

    public function show(string $reference): void
    {
        Auth::requireAdmin();

        $request = q(
            'SELECT pr.*, a.name AS application_name, a.slug AS application_slug
             FROM payment_requests pr
             JOIN applications a ON a.id = pr.application_id
             WHERE pr.reference = ? LIMIT 1',
            [$reference]
        )->fetch();

        if ($request === false) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }

        $attempts = q(
            'SELECT * FROM payment_attempts WHERE payment_request_id = ? ORDER BY id ASC',
            [(int) $request['id']]
        )->fetchAll();

        $transaction = q(
            'SELECT * FROM transactions WHERE payment_request_id = ? LIMIT 1',
            [(int) $request['id']]
        )->fetch();

        $checkoutIds = array_values(array_filter(array_column($attempts, 'checkout_request_id')));
        $callbacks = [];
        if ($checkoutIds !== []) {
            $in = implode(',', array_fill(0, count($checkoutIds), '?'));
            $callbacks = q(
                "SELECT id, provider, provider_reference, signature_valid, processed, received_at, processed_at
                 FROM provider_callbacks WHERE provider_reference IN ($in) ORDER BY id ASC",
                $checkoutIds
            )->fetchAll();
        }

        $webhooks = $transaction !== false
            ? q('SELECT id, event, status, attempts, last_response_code, created_at FROM webhook_deliveries WHERE transaction_id = ? ORDER BY id ASC', [(int) $transaction['id']])->fetchAll()
            : [];

        $disputes = $transaction !== false
            ? q('SELECT id, status, reason, created_at FROM disputes WHERE transaction_id = ? ORDER BY id ASC', [(int) $transaction['id']])->fetchAll()
            : [];

        $audit = q(
            "SELECT action, actor_type, actor_id, ip, created_at FROM audit_logs
             WHERE target_type = 'payment_request' AND target_id = ? ORDER BY id DESC LIMIT 30",
            [$reference]
        )->fetchAll();

        View::render('admin/payment-requests/show', [
            'title' => $request['reference'],
            'activeNav' => 'payment-requests',
            'request' => $request,
            'attempts' => $attempts,
            'transaction' => $transaction ?: null,
            'callbacks' => $callbacks,
            'webhooks' => $webhooks,
            'disputes' => $disputes,
            'audit' => $audit,
            'checkoutUrl' => absolute_url('/checkout/' . $reference),
        ], 'layouts/admin');
    }

    public function cancel(string $reference): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            PaymentRequestService::cancel($reference, 'administrator', Auth::admin()['id'] ?? null);
            flash('success', 'Payment request cancelled. Any provider attempt remains on record as history.');
        } catch (AppException $e) {
            flash('danger', $e->getMessage());
        }

        redirect('/admin/payment-requests/' . $reference);
    }

    private function filters(): array
    {
        $where = [];
        $params = [];

        $search = query_value('q');
        if ($search !== '') {
            $where[] = '(pr.reference LIKE ? OR pr.description LIKE ? OR pr.buyer_name LIKE ? OR pr.buyer_phone LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $status = query_value('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'pr.status = ?';
            $params[] = $status;
        }

        $application = (int) query_value('app', '0');
        if ($application > 0) {
            $where[] = 'pr.application_id = ?';
            $params[] = $application;
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

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    private function currentFilters(): array
    {
        return [
            'q' => query_value('q'),
            'status' => query_value('status'),
            'app' => query_value('app'),
            'from' => $this->dateValue(query_value('from')) ?? '',
            'to' => $this->dateValue(query_value('to')) ?? '',
        ];
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
