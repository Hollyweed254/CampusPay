<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Middleware\LoginThrottle;
use App\Repositories\AdminRepository;
use App\Services\AuditLogger;
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

        Auth::login($admin);
        AdminRepository::markLogin((int) $admin['id']);
        AuditLogger::log('admin.login');
        redirect('/admin');
    }

    public function logout(): void
    {
        Auth::requireAdmin();
        Csrf::requirePost();
        AuditLogger::log('admin.logout');
        Auth::logout();
        redirect('/login');
    }
}
