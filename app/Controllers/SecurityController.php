<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Repositories\AdminRepository;
use App\Services\AuditLogger;
use App\Services\SecretBox;
use App\Services\Setting;
use App\Services\Totp;

final class SecurityController
{
    public function index(): void
    {
        Auth::requireAdmin();
        $admin = $this->currentAdmin();

        $enabled = $admin['totp_enabled_at'] !== null;
        $secret = $enabled ? null : Auth::totpSetupSecret((int) $admin['id']);

        View::render('admin/security', [
            'title' => 'Security',
            'activeNav' => 'security',
            'admin' => $admin,
            'totpEnabled' => $enabled,
            'totpEnabledAt' => $admin['totp_enabled_at'],
            'pendingSecret' => $secret,
            'otpauthUri' => $secret === null
                ? null
                : Totp::provisioningUri((string) env('APP_NAME', 'CampusPay'), (string) $admin['email'], $secret),
            'enforced' => Setting::get('require_admin_totp') === '1',
            'isSuperadmin' => (Auth::admin()['role'] ?? '') === 'superadmin',
        ], 'layouts/admin');
    }

    /** Begin enrolment: mint a fresh seed and park it in the session to confirm. */
    public function startTotp(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();
        $admin = $this->currentAdmin();

        if ($admin['totp_enabled_at'] !== null) {
            flash('info', 'Two-factor authentication is already on.');
            redirect('/admin/security');
        }

        Auth::startTotpSetup((int) $admin['id'], Totp::generateSecret());
        AuditLogger::log('admin.totp_setup_started', 'administrator', (string) $admin['id']);
        flash('info', 'Add the setup key to your authenticator, then confirm with a generated code.');
        redirect('/admin/security');
    }

    /** Confirm enrolment: prove the authenticator works before trusting it. */
    public function enableTotp(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();
        $admin = $this->currentAdmin();

        if ($admin['totp_enabled_at'] !== null) {
            flash('info', 'Two-factor authentication is already on.');
            redirect('/admin/security');
        }

        $secret = Auth::totpSetupSecret((int) $admin['id']);
        if ($secret === null) {
            flash('danger', 'That setup session expired. Start again.');
            redirect('/admin/security');
        }

        $counter = Totp::verify($secret, post_value('code'));
        if ($counter === null) {
            AuditLogger::log('admin.totp_enrol_failed', 'administrator', (string) $admin['id']);
            flash('danger', 'That code did not match. Check the app and enter the current code.');
            redirect('/admin/security');
        }

        AdminRepository::setTotpSecret((int) $admin['id'], SecretBox::encrypt($secret));
        AdminRepository::enableTotp((int) $admin['id'], $counter);
        Auth::clearTotpSetup();
        AuditLogger::log('admin.totp_enrolled', 'administrator', (string) $admin['id']);
        flash('success', 'Two-factor authentication is now on.');
        redirect('/admin/security');
    }

    /** Turn 2FA off; needs the password again and is refused while policy enforces it. */
    public function disableTotp(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();
        $admin = $this->currentAdmin();

        if ($admin['totp_enabled_at'] === null) {
            flash('info', 'Two-factor authentication is not on.');
            redirect('/admin/security');
        }
        if (Setting::get('require_admin_totp') === '1') {
            flash('danger', 'Two-factor authentication is required by policy and cannot be turned off.');
            redirect('/admin/security');
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $admin['password_hash'])) {
            AuditLogger::log('admin.totp_disable_denied', 'administrator', (string) $admin['id']);
            flash('danger', 'Password is incorrect. Two-factor authentication was left unchanged.');
            redirect('/admin/security');
        }

        AdminRepository::disableTotp((int) $admin['id']);
        Auth::clearTotpSetup();
        AuditLogger::log('admin.totp_disabled', 'administrator', (string) $admin['id']);
        flash('success', 'Two-factor authentication is now off.');
        redirect('/admin/security');
    }

    /** Superadmin policy switch that forces enrolment at next sign-in. */
    public function updatePolicy(): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $enable = post_value('require_admin_totp') === '1';
        Setting::set('require_admin_totp', $enable ? '1' : '0');
        AuditLogger::log('settings.updated', 'system_settings', null, ['require_admin_totp' => $enable ? '1' : '0']);
        flash('success', $enable
            ? 'Administrators without two-factor will be required to set it up at next sign-in.'
            : 'Two-factor enforcement is off.');
        redirect('/admin/security');
    }

    /** Load the signed-in administrator's live row, or bounce to sign-in. */
    private function currentAdmin(): array
    {
        $admin = AdminRepository::find((int) (Auth::admin()['id'] ?? 0));
        if ($admin === null || $admin['status'] !== 'active') {
            Auth::logout();
            flash('danger', 'Your session ended. Please sign in again.');
            redirect('/login');
        }
        return $admin;
    }
}
