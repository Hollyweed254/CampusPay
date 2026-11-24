<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Csrf;
use App\Core\View;
use App\Services\CheckoutService;
use App\Services\Daraja;

/** Public checkout pages. No login; the reference is the capability. */
final class CheckoutController
{
    public function show(string $reference): void
    {
        $request = CheckoutService::load($reference);
        if ($request === null) {
            http_response_code(404);
            View::render('checkout/not_found', ['title' => 'Payment link not found'], 'layouts/checkout');
            return;
        }
        $request = CheckoutService::expireIfNeeded($request);

        View::render('checkout/show', [
            'title' => 'Pay ' . money_ksh($request['amount']) . ' · ' . $request['application_name'],
            'request' => $request,
            'darajaConfigured' => Daraja::configured(),
        ], 'layouts/checkout');
    }

    public function pay(string $reference): void
    {
        Csrf::requirePost();

        $request = CheckoutService::load($reference);
        if ($request === null) {
            http_response_code(404);
            View::render('checkout/not_found', ['title' => 'Payment link not found'], 'layouts/checkout');
            return;
        }
        $request = CheckoutService::expireIfNeeded($request);

        try {
            CheckoutService::initiateMpesa($request, post_value('phone'));
            flash('success', 'A payment prompt was sent to your phone. Enter your M-Pesa PIN on your handset to approve it.', 'checkout');
        } catch (AppException $e) {
            flash('danger', $e->getMessage(), 'checkout');
        }

        redirect('/checkout/' . $reference);
    }

    public function status(string $reference): void
    {
        $request = CheckoutService::load($reference);
        if ($request === null) {
            json_out(['ok' => false, 'error' => 'not_found', 'message' => 'Payment link not found.'], 404);
        }
        $request = CheckoutService::expireIfNeeded($request);
        $request = CheckoutService::syncStatus($request);

        json_out(['ok' => true] + CheckoutService::statusPayload($request));
    }
}
