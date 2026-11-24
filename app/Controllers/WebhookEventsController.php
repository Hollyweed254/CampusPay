<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\WebhookService;

final class WebhookEventsController
{
    private const STATUSES = ['pending', 'delivered', 'failed', 'exhausted'];
    private const PER_PAGE = 25;

    public function index(): void
    {
        Auth::requireAdmin();

        $where = [];
        $params = [];

        $status = query_value('status');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'wd.status = ?';
            $params[] = $status;
        }

        $event = query_value('event');
        if (in_array($event, ['payment.confirmed', 'payment.failed'], true)) {
            $where[] = 'wd.event = ?';
            $params[] = $event;
        }

        $application = (int) query_value('app', '0');
        if ($application > 0) {
            $where[] = 'wd.application_id = ?';
            $params[] = $application;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) q("SELECT COUNT(*) FROM webhook_deliveries wd $whereSql", $params)->fetchColumn();
        $pager = paginate($total, self::PER_PAGE, (int) query_value('page', '1'));

        $deliveries = q(
            "SELECT wd.*, a.name AS application_name, pr.reference AS request_reference
             FROM webhook_deliveries wd
             JOIN applications a ON a.id = wd.application_id
             LEFT JOIN transactions t ON t.id = wd.transaction_id
             LEFT JOIN payment_requests pr ON pr.id = t.payment_request_id
             $whereSql
             ORDER BY wd.id DESC
             LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
            $params
        )->fetchAll();

        $metrics = [
            'awaiting' => (int) q("SELECT COUNT(*) FROM webhook_deliveries WHERE status IN ('pending','failed')")->fetchColumn(),
            'delivered_today' => (int) q("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'delivered' AND delivered_at >= UTC_DATE()")->fetchColumn(),
            'exhausted_7d' => (int) q("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'exhausted' AND created_at >= (UTC_DATE() - INTERVAL 7 DAY)")->fetchColumn(),
            'last_delivery' => q("SELECT MAX(delivered_at) FROM webhook_deliveries WHERE status = 'delivered'")->fetchColumn() ?: null,
        ];

        View::render('admin/webhooks/index', [
            'title' => 'Webhook Events',
            'activeNav' => 'webhook-events',
            'deliveries' => $deliveries,
            'applications' => q('SELECT id, name FROM applications ORDER BY name ASC')->fetchAll(),
            'metrics' => $metrics,
            'statuses' => self::STATUSES,
            'filters' => [
                'status' => $status,
                'event' => $event,
                'app' => (string) $application === '0' ? '' : (string) $application,
            ],
            'pager' => $pager,
        ], 'layouts/admin');
    }

    public function resend(string $id): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        try {
            $outcome = WebhookService::resend((int) $id, (int) (Auth::admin()['id'] ?? 0));
            match ($outcome) {
                'delivered' => flash('success', 'Webhook delivered. The endpoint answered 2xx.'),
                'exhausted' => flash('danger', 'Delivery still failed and has reached the retry limit. Check the endpoint and the response error.'),
                default => flash('warning', 'Delivery failed again. It will be retried by the cron runner on the usual schedule.'),
            };
        } catch (\App\Core\AppException $e) {
            flash('danger', $e->getMessage());
        }

        redirect('/admin/webhook-events');
    }
}
