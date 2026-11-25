<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Middleware\LoginThrottle;
use App\Repositories\AdminRepository;
use App\Services\AuditLogger;
use App\Services\SecretBox;
use App\Services\Setting;
use App\Services\Totp;
use App\Validators\Validator;

final class AuthController
{
    /** Constant hash so unknown emails cost the same as wrong passwords. */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function showLogin(): void
    {
        if (Auth::admin() !== null) {
            redirect('/admin');
        }
        View::render('auth/login', ['title' => 'Sign in'], 'layouts/base');
    }

    public function login(): void
    {
        Csrf::requirePost();

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $ip = request_ip();

        if (Validator::email($email) !== null || Validator::required($password) !== null) {
            flash('danger', 'Enter a valid email and password.');
            redirect('/login');
        }

        if (LoginThrottle::blocked($email, $ip)) {
            AuditLogger::log('admin.login_blocked', 'administrator', null, ['email' => $email], 'system', null);
            http_response_code(429);
            View::render('auth/login', ['title' => 'Too many attempts', 'throttled' => true], 'layouts/base');
            return;
        }

        $admin = AdminRepository::findByEmail($email);
        $valid = $admin !== null
            && $admin['status'] === 'active'
            && password_verify($password, $admin['password_hash']);

        if ($admin === null) {
            password_verify($password, self::DUMMY_HASH);
        }

        LoginThrottle::record($email, $ip, $valid);

        if (!$valid) {
            AuditLogger::log('admin.login_failed', 'administrator', null, ['email' => $email], 'system', null);
            flash('danger', 'Email or password is incorrect.');
            redirect('/login');
        }

        $needsEnrolment = $admin['totp_enabled_at'] === null;

        // Enrolled accounts always stop at the second factor; unenrolled ones
        // only when policy (Settings → require_admin_totp) demands it.
        if (!$needsEnrolment || Setting::get('require_admin_totp') === '1') {
            Auth::startTotpChallenge((int) $admin['id'], $needsEnrolment);
            AuditLogger::log(
                'admin.login_second_factor',
                'administrator',
                (string) $admin['id'],
                ['enrol' => $needsEnrolment],
                'system',
                null
            );
            redirect('/login/2fa');
        }

        $this->completeLogin($admin);
    }

    public function showTotp(): void
    {
        if (Auth::admin() !== null) {
            redirect('/admin');
        }
        $challenge = Auth::totpChallenge();
        if ($challenge === null) {
            redirect('/login');
        }
        $admin = $this->activeChallengeAdmin($challenge);

        if ($challenge['enrol']) {
            $secret = Auth::totpSetupSecret((int) $admin['id']);
            if ($secret === null) {
                $secret = Totp::generateSecret();
                Auth::startTotpSetup((int) $admin['id'], $secret);
            }
            View::render('auth/totp', [
                'title' => 'Set up two-factor authentication',
                'mode' => 'enrol',
                'secret' => $secret,
                'otpauthUri' => Totp::provisioningUri((string) env('APP_NAME', 'CampusPay'), (string) $admin['email'], $secret),
            ], 'layouts/base');
            return;
        }

        View::render('auth/totp', [
            'title' => 'Two-factor verification',
            'mode' => 'verify',
        ], 'layouts/base');
    }

    public function verifyTotp(): void
    {
        Csrf::requirePost();

        $challenge = Auth::totpChallenge();
        if ($challenge === null) {
            redirect('/login');
        }
        $admin = $this->activeChallengeAdmin($challenge);
        $email = (string) $admin['email'];
        $ip = request_ip();

        if (LoginThrottle::blocked($email, $ip)) {
            Auth::clearTotpChallenge();
            Auth::clearTotpSetup();
            http_response_code(429);
            View::render('auth/login', ['title' => 'Too many attempts', 'throttled' => true], 'layouts/base');
            return;
        }

        $code = post_value('code');
        $secret = null;
        if ($challenge['enrol']) {
            $secret = Auth::totpSetupSecret((int) $admin['id']);
            $counter = $secret === null ? null : Totp::verify($secret, $code);
        } else {
            $counter = Totp::verifySealed(
                (string) ($admin['totp_secret'] ?? ''),
                $code,
                $admin['totp_last_counter'] === null ? null : (int) $admin['totp_last_counter']
            );
        }

        LoginThrottle::record($email, $ip, $counter !== null);

        if ($counter === null) {
            AuditLogger::log(
                $challenge['enrol'] ? 'admin.totp_enrol_failed' : 'admin.totp_failed',
                'administrator',
                (string) $admin['id'],
                [],
                'system',
                null
            );
            flash('danger', 'That code did not match. Check your authenticator and try again.');
            redirect('/login/2fa');
        }

        if ($challenge['enrol']) {
            AdminRepository::setTotpSecret((int) $admin['id'], SecretBox::encrypt((string) $secret));
            AdminRepository::enableTotp((int) $admin['id'], $counter);
            Auth::clearTotpSetup();
            Auth::clearTotpChallenge();
            $this->completeLogin($admin, 'admin.totp_enrolled');
            return;
        }

        AdminRepository::markTotpCounter((int) $admin['id'], $counter);
        Auth::clearTotpChallenge();
        $this->completeLogin($admin, 'admin.totp_verified');
    }

    public function logout(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();
        AuditLogger::log('admin.logout');
        Auth::logout();
        redirect('/login');
    }

    /** Resolve the account behind an in-progress challenge, or bounce to sign-in. */
    private function activeChallengeAdmin(array $challenge): array
    {
        $admin = AdminRepository::find((int) $challenge['admin_id']);
        if ($admin === null || $admin['status'] !== 'active') {
            Auth::clearTotpChallenge();
            Auth::clearTotpSetup();
            flash('danger', 'Your sign-in session ended. Please sign in again.');
            redirect('/login');
        }
        return $admin;
    }

    private function completeLogin(array $admin, string $auditAction = 'admin.login'): void
    {
        Auth::login($admin);
        AdminRepository::markLogin((int) $admin['id']);
        AuditLogger::log($auditAction);
        redirect('/admin');
    }
}
