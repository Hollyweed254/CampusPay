<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Services\AuditLogger;
use App\Services\SecretBox;

final class ApiCredentialsController
{
    public function index(): void
    {
        Auth::requireRole('superadmin');

        $applications = q(
            "SELECT a.id, a.name, a.slug, a.status,
                    (SELECT COUNT(*) FROM api_credentials c WHERE c.application_id = a.id AND c.status = 'active') AS active_keys
             FROM applications a ORDER BY a.name ASC"
        )->fetchAll();

        $credentials = q(
            'SELECT c.id, c.key_id, c.status, c.expires_at, c.last_used_at, c.created_at,
                    a.name AS application_name, a.id AS application_id
             FROM api_credentials c
             JOIN applications a ON a.id = c.application_id
             ORDER BY c.id DESC'
        )->fetchAll();

        $newKey = $_SESSION['new_api_key'] ?? null;
        unset($_SESSION['new_api_key']);

        View::render('admin/api-credentials/index', [
            'title' => 'API Credentials',
            'activeNav' => 'api-credentials',
            'applications' => $applications,
            'credentials' => $credentials,
            'newKey' => is_array($newKey) ? $newKey : null,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $application = q(
            'SELECT id, name FROM applications WHERE id = ? LIMIT 1',
            [(int) post_value('application_id', '0')]
        )->fetch();
        if ($application === false) {
            flash('danger', 'Choose an integration for the new credential.');
            redirect('/admin/api-credentials');
        }

        $expiresDays = post_value('expires_days');
        $expiresAt = null;
        if ($expiresDays !== '') {
            if (preg_match('/^\d{1,4}$/', $expiresDays) !== 1 || (int) $expiresDays < 1 || (int) $expiresDays > 3650) {
                flash('danger', 'Expiry must be between 1 and 3650 days, or left empty for no expiry.');
                redirect('/admin/api-credentials');
            }
            $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $expiresDays * 86400);
        }

        $keyId = 'cp_' . bin2hex(random_bytes(14));
        $secret = 'cps_' . bin2hex(random_bytes(24));

        q(
            'INSERT INTO api_credentials (application_id, key_id, secret_cipher, status, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [(int) $application['id'], $keyId, SecretBox::encrypt($secret), 'active', $expiresAt]
        );
        $credentialId = (int) Database::pdo()->lastInsertId();

        AuditLogger::log('api_credential.created', 'api_credential', (string) $credentialId, [
            'application' => $application['name'],
            'key_id' => $keyId,
            'expires_at' => $expiresAt,
        ]);

        $_SESSION['new_api_key'] = [
            'key_id' => $keyId,
            'secret' => $secret,
            'application' => $application['name'],
        ];
        flash('success', 'API credential created. The secret is shown once on this page — store it in the integrating application now.');
        redirect('/admin/api-credentials');
    }

    public function revoke(string $id): void
    {
        Auth::requireRole('superadmin');
        Csrf::requirePost();

        $credential = q(
            'SELECT c.id, c.key_id, c.status, a.name AS application_name
             FROM api_credentials c JOIN applications a ON a.id = c.application_id
             WHERE c.id = ? LIMIT 1',
            [(int) $id]
        )->fetch();

        if ($credential === false) {
            flash('danger', 'Credential not found.');
            redirect('/admin/api-credentials');
        }
        if ($credential['status'] === 'revoked') {
            flash('info', 'That credential was already revoked.');
            redirect('/admin/api-credentials');
        }

        q("UPDATE api_credentials SET status = 'revoked' WHERE id = ?", [(int) $credential['id']]);
        q('DELETE FROM api_nonces WHERE credential_id = ?', [(int) $credential['id']]);
        AuditLogger::log('api_credential.revoked', 'api_credential', (string) $credential['id'], [
            'application' => $credential['application_name'],
            'key_id' => $credential['key_id'],
        ]);

        flash('success', 'Credential revoked. Requests signed with it are rejected from now on; webhooks will use the next active key.');
        redirect('/admin/api-credentials');
    }
}
