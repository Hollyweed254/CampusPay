<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;
use App\Services\AuditLogger;
use App\Validators\Validator;

final class UsersController
{
    private const ROLES = ['admin', 'superadmin'];

    public function index(): void
    {
        Auth::requireRole('superadmin');

        $admins = q(
            'SELECT id, name, email, role, status, last_login_at, created_at
             FROM administrators ORDER BY id ASC'
        )->fetchAll();

        $oldForm = $_SESSION['old_admin_form'] ?? ['name' => '', 'email' => '', 'role' => 'admin'];
        unset($_SESSION['old_admin_form']);

        View::render('admin/users', [
            'title' => 'Users and Administrators',
            'activeNav' => 'users',
            'admins' => $admins,
            'currentId' => (int) (Auth::admin()['id'] ?? 0),
            'activeSuperadmins' => (int) q("SELECT COUNT(*) FROM administrators WHERE role = 'superadmin' AND status = 'active'")->fetchColumn(),
            'oldForm' => $oldForm,
        ], 'layouts/admin');
    }

    public function create(): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $name = post_value('name');
        $email = strtolower(post_value('email'));
        $role = post_value('role', 'admin');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (Validator::required($name) !== null || mb_strlen($name) > 100) {
            $errors[] = 'Enter a name of up to 100 characters.';
        }
        if (Validator::email($email) !== null) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors[] = 'Choose a valid role.';
        }
        if (strlen($password) < 12) {
            $errors[] = 'The password must be at least 12 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'The passwords do not match.';
        }

        if ($errors === [] && q('SELECT id FROM administrators WHERE email = ? LIMIT 1', [$email])->fetchColumn()) {
            $errors[] = 'An administrator with that email already exists.';
        }

        if ($errors !== []) {
            $_SESSION['old_admin_form'] = ['name' => $name, 'email' => $email, 'role' => $role];
            flash('danger', implode(' ', $errors));
            redirect('/admin/users');
        }

        q(
            'INSERT INTO administrators (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]
        );
        $newId = (int) \App\Core\Database::pdo()->lastInsertId();

        AuditLogger::log('administrator.created', 'administrator', (string) $newId, ['email' => $email, 'role' => $role]);
        flash('success', 'Administrator created.');
        redirect('/admin/users');
    }

    public function toggle(string $id): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $target = q('SELECT id, name, email, role, status FROM administrators WHERE id = ? LIMIT 1', [(int) $id])->fetch();
        if ($target === false) {
            flash('danger', 'Administrator not found.');
            redirect('/admin/users');
        }

        if ((int) $target['id'] === (int) (Auth::admin()['id'] ?? 0)) {
            flash('danger', 'You cannot disable your own account.');
            redirect('/admin/users');
        }

        $enabling = $target['status'] === 'disabled';
        if ($enabling) {
            q("UPDATE administrators SET status = 'active' WHERE id = ?", [(int) $target['id']]);
            AuditLogger::log('administrator.enabled', 'administrator', (string) $target['id'], ['email' => $target['email']]);
            flash('success', 'Administrator enabled.');
            redirect('/admin/users');
        }

        if ($target['role'] === 'superadmin'
            && (int) q("SELECT COUNT(*) FROM administrators WHERE role = 'superadmin' AND status = 'active'")->fetchColumn() <= 1) {
            flash('danger', 'At least one active superadmin must remain.');
            redirect('/admin/users');
        }

        q("UPDATE administrators SET status = 'disabled' WHERE id = ?", [(int) $target['id']]);
        AuditLogger::log('administrator.disabled', 'administrator', (string) $target['id'], ['email' => $target['email']]);
        flash('success', 'Administrator disabled.');
        redirect('/admin/users');
    }
}
