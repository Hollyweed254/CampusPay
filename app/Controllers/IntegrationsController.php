<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\AuditLogger;
use App\Services\Reference;

final class IntegrationsController
{
    private const SETTLEMENT_MODELS = ['merchant_collection', 'direct_payment', 'undecided'];

    public function index(): void
    {
        Auth::requireAdmin();

        $applications = q(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM payment_requests pr WHERE pr.application_id = a.id) AS request_count,
                    (SELECT COUNT(*) FROM payment_requests pr WHERE pr.application_id = a.id AND pr.status = 'succeeded') AS succeeded_count,
                    (SELECT COUNT(*) FROM api_credentials c WHERE c.application_id = a.id AND c.status = 'active') AS credential_count,
                    (SELECT MAX(pr.created_at) FROM payment_requests pr WHERE pr.application_id = a.id) AS last_request_at
             FROM applications a ORDER BY a.id ASC"
        )->fetchAll();

        View::render('admin/integrations/index', [
            'title' => 'Integrations',
            'activeNav' => 'integrations',
            'applications' => $applications,
            'settlementLabels' => self::settlementLabels(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireAdmin();

        View::render('admin/integrations/form', [
            'title' => 'New integration',
            'activeNav' => 'integrations',
            'application' => null,
            'settlementLabels' => self::settlementLabels(),
            'old' => $this->oldForm(),
        ], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        $data = $this->validate();
        if (is_string($data)) {
            flash('danger', $data);
            redirect('/admin/integrations/create');
        }

        $slug = Reference::slug($data['name']);
        $suffix = 2;
        while (q('SELECT id FROM applications WHERE slug = ? LIMIT 1', [$slug])->fetchColumn() !== false) {
            $slug = substr(Reference::slug($data['name']), 0, 55) . '-' . $suffix++;
        }

        q(
            'INSERT INTO applications (name, slug, status, cash_on_meeting_enabled, settlement_model, webhook_url)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$data['name'], $slug, 'active', $data['cash'], $data['settlement'], $data['webhook_url']]
        );
        $id = (int) \App\Core\Database::pdo()->lastInsertId();

        AuditLogger::log('integration.created', 'application', (string) $id, [
            'name' => $data['name'],
            'slug' => $slug,
            'settlement_model' => $data['settlement'],
        ]);
        flash('success', 'Integration created. Add an API credential before it can call the API.');
        redirect('/admin/integrations/' . $id);
    }

    public function show(string $id): void
    {
        Auth::requireAdmin();

        $application = q('SELECT * FROM applications WHERE id = ? LIMIT 1', [(int) $id])->fetch();
        if ($application === false) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Not found'], 'layouts/admin');
            return;
        }

        $stats = [
            'requests' => (int) q('SELECT COUNT(*) FROM payment_requests WHERE application_id = ?', [(int) $id])->fetchColumn(),
            'succeeded' => (int) q(
                "SELECT COUNT(*) FROM payment_requests WHERE application_id = ? AND status = 'succeeded'",
                [(int) $id]
            )->fetchColumn(),
            'volume' => q(
                "SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                 JOIN payment_requests pr ON pr.id = t.payment_request_id
                 WHERE pr.application_id = ? AND t.status = 'provider_confirmed'",
                [(int) $id]
            )->fetchColumn(),
        ];

        $recentRequests = q(
            'SELECT reference, description, amount, status, created_at
             FROM payment_requests WHERE application_id = ? ORDER BY id DESC LIMIT 6',
            [(int) $id]
        )->fetchAll();

        $credentials = q(
            'SELECT id, key_id, status, expires_at, last_used_at, created_at
             FROM api_credentials WHERE application_id = ? ORDER BY id DESC',
            [(int) $id]
        )->fetchAll();

        View::render('admin/integrations/form', [
            'title' => $application['name'],
            'activeNav' => 'integrations',
            'application' => $application,
            'stats' => $stats,
            'recentRequests' => $recentRequests,
            'credentials' => $credentials,
            'settlementLabels' => self::settlementLabels(),
            'old' => $this->oldForm(),
        ], 'layouts/admin');
    }

    public function update(string $id): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();

        $application = q('SELECT * FROM applications WHERE id = ? LIMIT 1', [(int) $id])->fetch();
        if ($application === false) {
            flash('danger', 'Integration not found.');
            redirect('/admin/integrations');
        }

        $data = $this->validate($application);
        if (is_string($data)) {
            flash('danger', $data);
            redirect('/admin/integrations/' . (int) $id);
        }

        q(
            'UPDATE applications SET name = ?, status = ?, cash_on_meeting_enabled = ?, settlement_model = ?, webhook_url = ?
             WHERE id = ?',
            [$data['name'], $data['status'], $data['cash'], $data['settlement'], $data['webhook_url'], (int) $id]
        );

        AuditLogger::log('integration.updated', 'application', (string) $id, [
            'name' => $data['name'],
            'status' => $data['status'],
            'settlement_model' => $data['settlement'],
            'cash_on_meeting' => (bool) $data['cash'],
        ]);
        flash('success', 'Integration updated.');
        redirect('/admin/integrations/' . (int) $id);
    }

    private function validate(?array $existing = null): array|string
    {
        $name = post_value('name');
        $settlement = post_value('settlement_model', 'undecided');
        $webhookUrl = post_value('webhook_url');
        $status = post_value('status', 'active');
        $cash = isset($_POST['cash_on_meeting_enabled']) ? 1 : 0;

        $_SESSION['old_integration_form'] = ['name' => $name, 'settlement_model' => $settlement, 'webhook_url' => $webhookUrl];

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return 'The integration name must be between 2 and 120 characters.';
        }
        if (!in_array($settlement, self::SETTLEMENT_MODELS, true)) {
            return 'Choose a valid settlement model.';
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            return 'Choose a valid status.';
        }
        if ($webhookUrl !== '') {
            $valid = filter_var($webhookUrl, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', $webhookUrl) === 1;
            if (!$valid) {
                return 'The webhook URL must start with http:// or https:// and be a valid URL.';
            }
            if (env('APP_ENV') === 'production' && !str_starts_with(strtolower($webhookUrl), 'https://')) {
                return 'Production webhooks must use HTTPS.';
            }
        }

        return [
            'name' => $name,
            'settlement' => $settlement,
            'status' => $status,
            'cash' => $cash,
            'webhook_url' => $webhookUrl === '' ? null : $webhookUrl,
        ];
    }

    private function oldForm(): array
    {
        $old = $_SESSION['old_integration_form'] ?? ['name' => '', 'settlement_model' => 'undecided', 'webhook_url' => ''];
        unset($_SESSION['old_integration_form']);
        return $old;
    }

    private static function settlementLabels(): array
    {
        return [
            'merchant_collection' => 'Merchant collection — CampusPay verifies M-Pesa into the deployment shortcode.',
            'direct_payment' => 'Direct payment — the parties pay off-platform; CampusPay records a reported transaction only.',
            'undecided' => 'Undecided — checkout refuses to start until this is chosen.',
        ];
    }
}
