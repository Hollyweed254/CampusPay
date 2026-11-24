<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\AuditLogger;

final class TransactionsController
{
    private const STATUSES = ['pending', 'reported', 'provider_confirmed', 'failed', 'cancelled', 'expired', 'disputed'];
    private const PER_PAGE = 20;

    public function index(): void
    {
        Auth::requireAdmin();

        [$where, $params] = $this->filters();
        $total = (int) q(
            "SELECT COUNT(*) FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             $where",
            $params
        )->fetchColumn();
        $pager = paginate($total, self::PER_PAGE, (int) query_value('page', '1'));

        $rows = q(
            "SELECT t.*, pr.reference, pr.description, pr.payment_method, pr.created_via, a.name AS application_name
             FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             $where
             ORDER BY t.id DESC
             LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        )->fetchAll();

        $applications = q('SELECT id, name FROM applications ORDER BY name ASC')->fetchAll();

        View::render('admin/transactions/index', [
            'title' => 'Transactions',
            'activeNav' => 'transactions',
            'transactions' => $rows,
            'applications' => $applications,
            'statuses' => self::STATUSES,
            'filters' => $this->currentFilters(),
            'pager' => $pager,
        ], 'layouts/admin');
    }

    public function export(): void
    {
        Auth::requireAdmin();

        [$where, $params] = $this->filters();
        $rows = q(
            "SELECT t.id, pr.reference, t.provider_receipt, t.provider_reference, a.name AS application_name,
                    pr.description, t.amount, t.currency, t.provider, t.status, t.verified_at, t.created_at
             FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             $where
             ORDER BY t.id DESC",
            $params
        )->fetchAll();

        $filename = 'campuspay-transactions-' . gmdate('Ymd-Hi') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Reference', 'Receipt', 'Provider reference', 'Application', 'Description', 'Amount', 'Currency', 'Method', 'Status', 'Verified at (UTC)', 'Created at (UTC)']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $this->csvCell((string) $row['reference']),
                $this->csvCell((string) ($row['provider_receipt'] ?? '')),
                $this->csvCell((string) ($row['provider_reference'] ?? '')),
                $this->csvCell((string) $row['application_name']),
                $this->csvCell((string) $row['description']),
                $row['amount'],
                $row['currency'],
                $row['provider'],
                $row['status'],
                (string) ($row['verified_at'] ?? ''),
                (string) $row['created_at'],
            ]);
        }
        fclose($out);

        AuditLogger::log('transactions.exported', 'transactions', null, [
            'rows' => count($rows),
            'filters' => $this->currentFilters(),
        ]);
        exit;
    }

    public function show(string $id): void
    {
        Auth::requireAdmin();

        $transaction = q(
            'SELECT t.*, pr.reference, pr.description, pr.amount AS request_amount, pr.payment_method, pr.created_via,
                    pr.buyer_name, pr.buyer_phone, pr.status AS request_status, pr.created_at AS request_created_at,
                    pr.expires_at, pr.id AS request_id,
                    a.id AS application_id, a.name AS application_name
             FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             WHERE t.id = ? LIMIT 1',
            [(int) $id]
        )->fetch();

        if ($transaction === false) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }

        $attempts = q(
            'SELECT * FROM payment_attempts WHERE payment_request_id = ? ORDER BY id ASC',
            [(int) $transaction['request_id']]
        )->fetchAll();

        $checkoutIds = array_values(array_filter(array_column($attempts, 'checkout_request_id')));
        $callbacks = [];
        if ($checkoutIds !== []) {
            $in = implode(',', array_fill(0, count($checkoutIds), '?'));
            $callbacks = q(
                "SELECT id, provider, provider_reference, signature_valid, processed, received_at, processed_at, payload
                 FROM provider_callbacks WHERE provider_reference IN ($in) ORDER BY id ASC",
                $checkoutIds
            )->fetchAll();
        }

        $webhooks = q(
            'SELECT id, event, status, attempts, last_response_code, last_error, next_retry_at, delivered_at, created_at
             FROM webhook_deliveries WHERE transaction_id = ? ORDER BY id ASC',
            [(int) $transaction['id']]
        )->fetchAll();

        $disputes = q(
            'SELECT id, status, reason, resolution, opened_by_type, resolved_at, created_at
             FROM disputes WHERE transaction_id = ? ORDER BY id ASC',
            [(int) $transaction['id']]
        )->fetchAll();
        $activeDispute = null;
        foreach ($disputes as $dispute) {
            if (in_array($dispute['status'], ['open', 'under_review'], true)) {
                $activeDispute = $dispute;
                break;
            }
        }
        $canDispute = $activeDispute === null
            && in_array($transaction['status'], ['provider_confirmed', 'reported', 'failed'], true);

        $reconciliation = q(
            'SELECT provider_status, matches, details, checked_at FROM reconciliation_records
             WHERE transaction_id = ? ORDER BY id DESC LIMIT 20',
            [(int) $transaction['id']]
        )->fetchAll();

        $audit = q(
            "SELECT action, actor_type, actor_id, ip, created_at FROM audit_logs
             WHERE (target_type = 'payment_request' AND target_id = ?)
                OR (target_type = 'transaction' AND target_id = ?)
             ORDER BY id DESC LIMIT 40",
            [$transaction['reference'], (string) $transaction['id']]
        )->fetchAll();

        View::render('admin/transactions/show', [
            'title' => 'Transaction ' . $transaction['reference'],
            'activeNav' => 'transactions',
            'transaction' => $transaction,
            'attempts' => $attempts,
            'callbacks' => $callbacks,
            'webhooks' => $webhooks,
            'disputes' => $disputes,
            'activeDispute' => $activeDispute,
            'canDispute' => $canDispute,
            'reconciliation' => $reconciliation,
            'audit' => $audit,
        ], 'layouts/admin');
    }

    private function filters(): array
    {
        $where = [];
        $params = [];

        $search = query_value('q');
        if ($search !== '') {
            $where[] = '(pr.reference LIKE ? OR pr.description LIKE ? OR t.provider_receipt LIKE ? OR t.provider_reference LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $status = query_value('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $application = (int) query_value('app', '0');
        if ($application > 0) {
            $where[] = 'pr.application_id = ?';
            $params[] = $application;
        }

        $from = $this->dateValue(query_value('from'));
        if ($from !== null) {
            $where[] = 't.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = $this->dateValue(query_value('to'));
        if ($to !== null) {
            $where[] = 't.created_at <= ?';
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

    /** Prevent spreadsheet formula injection in exported cells. */
    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
