<?php
declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\MpesaCallbackController;

$router->get('/api/v1/health', [new ApiController(), 'health']);
$router->post('/api/v1/payment-requests', [new ApiController(), 'createPaymentRequest']);
$router->get('/api/v1/payment-requests', [new ApiController(), 'listPaymentRequests']);
$router->get('/api/v1/payment-requests/{reference}', [new ApiController(), 'showPaymentRequest']);
$router->post('/api/v1/payment-requests/{reference}/cancel', [new ApiController(), 'cancelPaymentRequest']);
$router->get('/api/v1/transactions/{reference}', [new ApiController(), 'showTransaction']);
$router->post('/api/mpesa/callback/{token}', [new MpesaCallbackController(), 'handle']);
