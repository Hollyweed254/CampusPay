<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Services\AuditLogger;
use App\Services\Daraja;
use App\Services\MpesaService;

/**
 * Safaricom posts every STK result here. The URL contains a private token;
 * the optional IP allow-list narrows it further. No session, no CSRF: the
 * caller is a server, and the token is the credential.
 */
final class MpesaCallbackController
{
    private const MAX_BYTES = 65536;

    public function handle(string $token): void
    {
        $expected = Daraja::callbackToken();
        if ($expected === '') {
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Callback token is not configured.'], 503);
        }
        if (!hash_equals($expected, $token)) {
            $this->reject('bad_token');
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Forbidden.'], 403);
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) env('DARAJA_CALLBACK_IPS', '')))));
        if ($allowed !== [] && !in_array(request_ip(), $allowed, true)) {
            $this->reject('ip_not_allowed');
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Forbidden.'], 403);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Empty body.'], 400);
        }
        if (strlen($raw) > self::MAX_BYTES) {
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Payload too large.'], 413);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON.'], 400);
        }

        try {
            $result = MpesaService::receiveCallback($payload);
        } catch (AppException $e) {
            json_out(['ResultCode' => 1, 'ResultDesc' => $e->getMessage()], $e->httpStatus);
        } catch (\Throwable $e) {
            log_exception($e);
            json_out(['ResultCode' => 1, 'ResultDesc' => 'Temporary failure; retry later.'], 500);
        }

        json_out([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
            'CampusPayOutcome' => $result['outcome'],
        ], 200);
    }

    private function reject(string $reason): void
    {
        try {
            AuditLogger::log('mpesa.callback_rejected', null, null, [
                'reason' => $reason,
                'ip' => request_ip(),
            ], 'system');
        } catch (\Throwable $e) {
            log_exception($e);
        }
    }
}
