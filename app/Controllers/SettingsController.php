<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\AuditLogger;
use App\Services\Daraja;
use App\Services\Setting;

final class SettingsController
{
    /** min, max, label, hint. */
    private const FIELDS = [
        'checkout_ttl_minutes' => [5, 1440, 'Checkout window (minutes)', 'How long a checkout link stays payable before the upkeep cron expires it.'],
        'max_amount_kes' => [1, 1000000, 'Maximum amount (KSh)', 'Largest payment request the API and admin will accept.'],
        'status_check_cooldown_seconds' => [5, 300, 'Status-query cooldown (seconds)', 'Minimum gap between M-Pesa status queries for one request.'],
        'reconciliation_stale_after_minutes' => [1, 60, 'Reconcile attempts after (minutes)', 'An open STK attempt must be this old before the cron queries it.'],
        'webhook_max_attempts' => [1, 10, 'Webhook attempts', 'Delivery tries before an event is marked exhausted.'],
        'api_rate_limit_per_minute' => [0, 10000, 'API requests per minute', 'Signed requests allowed per API key per minute. 0 disables the limit.'],
    ];

    public function index(): void
    {
        Auth::requireRole('superadmin');

        $settings = Setting::all();
        $values = [];
        foreach (self::FIELDS as $key => [$min, $max, $label]) {
            $values[$key] = Setting::int($key, $min);
        }

        View::render('admin/settings', [
            'title' => 'Settings',
            'activeNav' => 'settings',
            'fields' => self::FIELDS,
            'values' => $values,
            'settings' => $settings,
            'readiness' => [
                'APP_KEY' => env('APP_KEY') !== null && env('APP_KEY') !== '',
                'Daraja credentials' => Daraja::configured(),
                'Callback IP allow-list' => (string) env('DARAJA_CALLBACK_IPS', '') !== '',
                'Production mode' => env('APP_ENV') === 'production',
            ],
            'darajaEnv' => Daraja::environment(),
            'dbTime' => (string) q('SELECT UTC_TIMESTAMP()')->fetchColumn(),
            'lastCron' => $settings['last_cron_run_at']['value'] ?? null,
        ], 'layouts/admin');
    }

    public function update(): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $errors = [];
        $changes = [];
        foreach (self::FIELDS as $key => [$min, $max, $label]) {
            $raw = trim((string) post_value($key));
            if (preg_match('/^\d{1,7}$/', $raw) !== 1) {
                $errors[] = $label . ' must be a whole number.';
                continue;
            }
            $new = (int) $raw;
            if ($new < $min || $new > $max) {
                $errors[] = sprintf('%s must be between %d and %d.', $label, $min, $max);
                continue;
            }
            $old = Setting::int($key, $min);
            if ($new !== $old) {
                Setting::set($key, (string) $new);
                $changes[$key] = ['from' => $old, 'to' => $new];
            }
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('/admin/settings');
        }

        if ($changes === []) {
            flash('info', 'No settings changed.');
            redirect('/admin/settings');
        }

        AuditLogger::log('settings.updated', 'system_settings', null, ['changed' => $changes]);
        flash('success', 'Settings saved. New values apply to the next request.');
        redirect('/admin/settings');
    }
}
