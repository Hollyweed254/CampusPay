<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

final class AdminController
{
    /**
     * Sidebar registry. 'stage' is the stage that builds the section (null = live).
     * 'role' restricts a section to superadmins (enforced server-side as well).
     */
    public const SECTIONS = [
        'overview' => ['label' => 'Overview', 'stage' => null, 'icon' => 'bi-speedometer2', 'desc' => '', 'role' => null],
        'transactions' => ['label' => 'Transactions', 'stage' => null, 'icon' => 'bi-arrow-left-right', 'desc' => 'Provider-verified payment records with their full event history.', 'role' => null],
        'payment-requests' => ['label' => 'Payment Requests', 'stage' => null, 'icon' => 'bi-inboxes', 'desc' => 'Requests created through the API, checkout links and their statuses.', 'role' => null],
        'integrations' => ['label' => 'Integrations', 'stage' => null, 'icon' => 'bi-diagram-3', 'desc' => 'Applications allowed to create payment requests.', 'role' => null],
        'webhook-events' => ['label' => 'Webhook Events', 'stage' => null, 'icon' => 'bi-broadcast', 'desc' => 'Signed deliveries to integrating applications with retry history.', 'role' => null],
        'disputes' => ['label' => 'Disputes', 'stage' => null, 'icon' => 'bi-exclamation-triangle', 'desc' => 'Disputed transactions and their resolution trail.', 'role' => null],
        'reconciliation' => ['label' => 'Reconciliation', 'stage' => null, 'icon' => 'bi-clipboard-check', 'desc' => 'Provider status checks compared against recorded transactions.', 'role' => null],
        'api-credentials' => ['label' => 'API Credentials', 'stage' => null, 'icon' => 'bi-key', 'desc' => 'Key rotation and revocation for integrations.', 'role' => 'superadmin'],
        'users' => ['label' => 'Users and Administrators', 'stage' => null, 'icon' => 'bi-people', 'desc' => 'Administrator accounts and role management.', 'role' => 'superadmin'],
        'security' => ['label' => 'Security', 'stage' => null, 'icon' => 'bi-shield-lock', 'desc' => 'Your account\'s two-factor authentication and sign-in protection.', 'role' => null],
        'audit-logs' => ['label' => 'Audit Logs', 'stage' => null, 'icon' => 'bi-journal-text', 'desc' => '', 'role' => null],
        'settings' => ['label' => 'Settings', 'stage' => null, 'icon' => 'bi-gear', 'desc' => 'System configuration and environment readiness.', 'role' => 'superadmin'],
    ];

    public static function navFor(string $role): array
    {
        return array_filter(self::SECTIONS, static fn (array $item): bool => $item['role'] === null || $item['role'] === $role);
    }

    public static function overview(): void
    {
        Auth::requireAdmin();

        $metrics = [
            'total_requests' => (int) q('SELECT COUNT(*) FROM payment_requests')->fetchColumn(),
            'succeeded' => (int) q("SELECT COUNT(*) FROM payment_requests WHERE status = 'succeeded'")->fetchColumn(),
            'awaiting' => (int) q("SELECT COUNT(*) FROM payment_requests WHERE status IN ('pending','processing')")->fetchColumn(),
            'failed' => (int) q("SELECT COUNT(*) FROM payment_requests WHERE status IN ('failed','cancelled','expired')")->fetchColumn(),
            'disputed' => (int) q("SELECT COUNT(*) FROM transactions WHERE status = 'disputed'")->fetchColumn(),
            'processed_total' => q("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'provider_confirmed'")->fetchColumn(),
            'administrators' => (int) q("SELECT COUNT(*) FROM administrators WHERE status = 'active'")->fetchColumn(),
        ];

        $settled = $metrics['succeeded'] + $metrics['failed'];
        $metrics['success_rate'] = $settled > 0 ? round($metrics['succeeded'] * 100 / $settled, 1) : null;

        $activity = [];
        $counts = [];
        foreach (q(
            'SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM payment_requests WHERE created_at >= (UTC_DATE() - INTERVAL 13 DAY)
             GROUP BY DATE(created_at)'
        )->fetchAll() as $row) {
            $counts[$row['d']] = (int) $row['c'];
        }
        for ($i = 13; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-$i days"));
            $activity[] = ['date' => $day, 'label' => gmdate('d M', strtotime($day)), 'count' => $counts[$day] ?? 0];
        }

        $statusRows = q(
            'SELECT status, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total
             FROM payment_requests GROUP BY status'
        )->fetchAll();
        $byStatus = [];
        foreach ($statusRows as $row) {
            $byStatus[$row['status']] = ['count' => (int) $row['c'], 'total' => $row['total']];
        }
        $statusOrder = ['pending', 'processing', 'succeeded', 'failed', 'cancelled', 'expired'];
        $breakdown = [];
        foreach ($statusOrder as $status) {
            $breakdown[$status] = $byStatus[$status] ?? ['count' => 0, 'total' => '0.00'];
        }

        $recentTransactions = q(
            'SELECT t.id, t.amount, t.status, t.provider_receipt, t.verified_at, t.created_at,
                    pr.reference, pr.description, a.name AS application
             FROM transactions t
             JOIN payment_requests pr ON pr.id = t.payment_request_id
             JOIN applications a ON a.id = pr.application_id
             ORDER BY t.id DESC LIMIT 5'
        )->fetchAll();

        $recentAudit = q(
            'SELECT action, actor_type, created_at FROM audit_logs ORDER BY id DESC LIMIT 6'
        )->fetchAll();

        $readiness = [
            'Database' => true,
            'Daraja credentials' => env('DARAJA_CONSUMER_KEY') !== null
                && env('DARAJA_CONSUMER_SECRET') !== null
                && env('DARAJA_SHORTCODE') !== null
                && env('DARAJA_PASSKEY') !== null,
            'Callback token' => env('DARAJA_CALLBACK_TOKEN') !== null,
            'Application key' => env('APP_KEY') !== null,
        ];

        View::render('admin/overview', [
            'title' => 'Overview',
            'activeNav' => 'overview',
            'metrics' => $metrics,
            'activity' => $activity,
            'breakdown' => $breakdown,
            'recentTransactions' => $recentTransactions,
            'recentAudit' => $recentAudit,
            'readiness' => $readiness,
        ], 'layouts/admin');
    }

    public function section(string $section): void
    {
        Auth::requireAdmin();

        $definition = self::SECTIONS[$section] ?? null;
        if ($definition === null || $section === 'overview' || $section === 'audit-logs'
            || $section === 'users' || !isset($definition['stage']) || $definition['stage'] === null) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }

        if ($definition['role'] !== null && (Auth::admin()['role'] ?? '') !== $definition['role']) {
            http_response_code(403);
            View::render('errors/403', ['title' => 'Forbidden'], 'layouts/admin');
            return;
        }

        View::render('admin/placeholder', [
            'title' => $definition['label'],
            'activeNav' => $section,
            'sectionTitle' => $definition['label'],
            'stage' => $definition['stage'],
            'description' => $definition['desc'],
        ], 'layouts/admin');
    }

    public function auditLogs(): void
    {
        Auth::requireAdmin();

        $entries = q(
            'SELECT actor_type, actor_id, action, target_type, target_id, ip, created_at
             FROM audit_logs ORDER BY id DESC LIMIT 30'
        )->fetchAll();
        $total = (int) q('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

        View::render('admin/audit', [
            'title' => 'Audit Logs',
            'activeNav' => 'audit-logs',
            'entries' => $entries,
            'total' => $total,
        ], 'layouts/admin');
    }
}
