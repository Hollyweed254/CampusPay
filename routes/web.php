<?php
declare(strict_types=1);

use App\Controllers\PublicController;
use App\Controllers\UsersController;
use App\Controllers\AdminController;
use App\Controllers\ApiCredentialsController;
use App\Controllers\AuthController;
use App\Controllers\CheckoutController;
use App\Controllers\DisputesController;
use App\Controllers\IntegrationsController;
use App\Controllers\PaymentRequestsController;
use App\Controllers\ReconciliationController;
use App\Controllers\SecurityController;
use App\Controllers\SettingsController;
use App\Controllers\TransactionsController;
use App\Controllers\WebhookEventsController;

$router->get('/', [new PublicController(), 'landing']);
$router->get('/legal/privacy', [new PublicController(), 'privacy']);
$router->get('/legal/terms', [new PublicController(), 'terms']);

$router->get('/login', [new AuthController(), 'showLogin']);
$router->post('/login', [new AuthController(), 'login']);
$router->get('/login/2fa', [new AuthController(), 'showTotp']);
$router->post('/login/2fa', [new AuthController(), 'verifyTotp']);
$router->post('/logout', [new AuthController(), 'logout']);

$router->get('/admin', [new AdminController(), 'overview']);
$router->get('/admin/audit-logs', [new AdminController(), 'auditLogs']);
$router->get('/admin/users', [new UsersController(), 'index']);
$router->post('/admin/users', [new UsersController(), 'create']);
$router->post('/admin/users/{id}/status', [new UsersController(), 'toggle']);

$router->get('/admin/payment-requests', [new PaymentRequestsController(), 'index']);
$router->get('/admin/payment-requests/create', [new PaymentRequestsController(), 'create']);
$router->post('/admin/payment-requests', [new PaymentRequestsController(), 'store']);
$router->get('/admin/payment-requests/{reference}', [new PaymentRequestsController(), 'show']);
$router->post('/admin/payment-requests/{reference}/cancel', [new PaymentRequestsController(), 'cancel']);

$router->get('/admin/transactions', [new TransactionsController(), 'index']);
$router->get('/admin/transactions/export', [new TransactionsController(), 'export']);
$router->get('/admin/transactions/{id}', [new TransactionsController(), 'show']);
$router->post('/admin/transactions/{id}/disputes', [new DisputesController(), 'open']);

$router->get('/admin/disputes', [new DisputesController(), 'index']);
$router->get('/admin/disputes/new', [new DisputesController(), 'create']);
$router->get('/admin/disputes/{id}', [new DisputesController(), 'show']);
$router->post('/admin/disputes/{id}/review', [new DisputesController(), 'review']);
$router->post('/admin/disputes/{id}/resolve', [new DisputesController(), 'resolve']);
$router->post('/admin/disputes/{id}/reject', [new DisputesController(), 'reject']);

$router->get('/admin/integrations', [new IntegrationsController(), 'index']);
$router->get('/admin/integrations/create', [new IntegrationsController(), 'create']);
$router->post('/admin/integrations', [new IntegrationsController(), 'store']);
$router->get('/admin/integrations/{id}', [new IntegrationsController(), 'show']);
$router->post('/admin/integrations/{id}', [new IntegrationsController(), 'update']);

$router->get('/admin/reconciliation', [new ReconciliationController(), 'index']);
$router->post('/admin/reconciliation/run', [new ReconciliationController(), 'run']);
$router->post('/admin/reconciliation/attempts/{id}/check', [new ReconciliationController(), 'check']);

$router->get('/admin/webhook-events', [new WebhookEventsController(), 'index']);
$router->post('/admin/webhook-events/{id}/resend', [new WebhookEventsController(), 'resend']);

$router->get('/admin/api-credentials', [new ApiCredentialsController(), 'index']);
$router->post('/admin/api-credentials', [new ApiCredentialsController(), 'store']);
$router->post('/admin/api-credentials/{id}/revoke', [new ApiCredentialsController(), 'revoke']);

$router->get('/admin/settings', [new SettingsController(), 'index']);
$router->post('/admin/settings', [new SettingsController(), 'update']);

$router->get('/admin/security', [new SecurityController(), 'index']);
$router->post('/admin/security/totp/start', [new SecurityController(), 'startTotp']);
$router->post('/admin/security/totp/enable', [new SecurityController(), 'enableTotp']);
$router->post('/admin/security/totp/disable', [new SecurityController(), 'disableTotp']);
$router->post('/admin/security/policy', [new SecurityController(), 'updatePolicy']);

$router->get('/admin/{section}', [new AdminController(), 'section']);

$router->get('/checkout/{reference}', [new CheckoutController(), 'show']);
$router->post('/checkout/{reference}/pay', [new CheckoutController(), 'pay']);
$router->get('/checkout/{reference}/status', [new CheckoutController(), 'status']);
