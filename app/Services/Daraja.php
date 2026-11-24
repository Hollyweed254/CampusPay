<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\HttpClient;

/**
 * Daraja (Safaricom M-Pesa) gateway boundary.
 *
 * Fail closed: with any credential missing, no OAuth token is fetched and no
 * STK Push leaves the server. A network failure after a push is ambiguous, so
 * it is reported as an unknown outcome and the attempt stays open for a query
 * instead of being marked failed.
 */
final class Daraja
{
    public const SANDBOX = 'https://sandbox.safaricom.co.ke';
    public const PRODUCTION = 'https://api.safaricom.co.ke';

    private static ?string $token = null;
    private static int $tokenExpiresAt = 0;

    /** Bearer tokens last an hour; keep them across requests to respect rate limits. */
    private const TOKEN_CACHE = BASE_PATH . '/storage/cache/daraja-token.json';

    public static function environment(): string
    {
        return strtolower((string) env('DARAJA_ENV', 'sandbox')) === 'production'
            ? 'production'
            : 'sandbox';
    }

    public static function baseUrl(): string
    {
        return self::environment() === 'production' ? self::PRODUCTION : self::SANDBOX;
    }

    public static function configured(): bool
    {
        foreach (['DARAJA_CONSUMER_KEY', 'DARAJA_CONSUMER_SECRET', 'DARAJA_SHORTCODE', 'DARAJA_PASSKEY', 'DARAJA_CALLBACK_TOKEN'] as $key) {
            if (env($key) === null) {
                return false;
            }
        }
        return true;
    }

    public static function shortcode(): string
    {
        return (string) env('DARAJA_SHORTCODE', '');
    }

    public static function callbackToken(): string
    {
        return (string) env('DARAJA_CALLBACK_TOKEN', '');
    }

    /** The public URL Safaricom posts the result to. */
    public static function callbackUrl(): string
    {
        $configured = trim((string) env('DARAJA_CALLBACK_URL', ''));
        if ($configured !== '') {
            return $configured;
        }
        return absolute_url('/api/mpesa/callback/' . self::callbackToken());
    }

    private static function transactionType(): string
    {
        $type = (string) env('DARAJA_TRANSACTION_TYPE', 'CustomerPayBillOnline');
        return in_array($type, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)
            ? $type
            : 'CustomerPayBillOnline';
    }

    /**
     * @return array{checkout_request_id:string, merchant_request_id:string, response_code:string, response_description:string, customer_message:string}
     */
    public static function stkPush(array $paymentRequest, string $phone): array
    {
        if (!self::configured()) {
            throw new AppException(
                'provider_not_configured',
                'M-Pesa is not configured on this deployment. The request stays open; contact the merchant.',
                503
            );
        }

        $amountCents = (int) round(((float) $paymentRequest['amount']) * 100);
        if ($amountCents % 100 !== 0) {
            throw new AppException(
                'unsupported_amount',
                'M-Pesa can only charge whole shillings. Ask the merchant for a new request.',
                422
            );
        }

        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
        $password = base64_encode(self::shortcode() . (string) env('DARAJA_PASSKEY', '') . $timestamp);

        $payload = [
            'BusinessShortCode' => self::shortcode(),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => self::transactionType(),
            'Amount' => intdiv($amountCents, 100),
            'PartyA' => $phone,
            'PartyB' => self::shortcode(),
            'PhoneNumber' => $phone,
            'CallBackURL' => self::callbackUrl(),
            'AccountReference' => mb_substr((string) $paymentRequest['reference'], 0, 12),
            'TransactionDesc' => mb_substr((string) $paymentRequest['description'], 0, 13),
        ];

        $response = self::post('/mpesa/stkpush/v1/processrequest', $payload);
        $data = self::decode($response['body']);

        if ($response['error'] !== null || $response['status'] >= 500) {
            throw new AppException(
                'provider_timeout',
                'M-Pesa did not answer in time. The prompt may still arrive — check the status before trying again.',
                504,
                ['http_status' => $response['status'], 'transport' => $response['error'] !== null]
            );
        }

        if (!is_array($data)) {
            throw new AppException(
                'provider_bad_response',
                'M-Pesa returned an unreadable response. Check the status before trying again.',
                502,
                ['http_status' => $response['status']]
            );
        }

        $code = isset($data['ResponseCode']) ? (string) $data['ResponseCode'] : '';
        if ($code !== '0') {
            $message = self::providerMessage($data);
            throw new AppException(
                'provider_rejected',
                $message,
                502,
                [
                    'response_code' => self::shortText((string) ($data['errorCode'] ?? $code), 16),
                    'provider_message' => $message,
                ]
            );
        }

        $checkoutId = trim((string) ($data['CheckoutRequestID'] ?? ''));
        $merchantId = trim((string) ($data['MerchantRequestID'] ?? ''));
        if ($checkoutId === '' || $merchantId === '') {
            throw new AppException(
                'provider_bad_response',
                'M-Pesa accepted the request but did not return its identifiers. Check the status before trying again.',
                502,
                ['http_status' => $response['status']]
            );
        }

        return [
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
            'response_code' => '0',
            'response_description' => self::shortText((string) ($data['ResponseDescription'] ?? 'Success. Request accepted for processing.'), 255),
            'customer_message' => self::shortText((string) ($data['CustomerMessage'] ?? ''), 255),
        ];
    }

    /**
     * STK Push Query. A still-processing transaction comes back as HTTP 500
     * with a known marker; that is not an error, it is "not settled yet".
     *
     * @return array{pending:bool, result_code:?int, result_desc:string, http_status:int}
     */
    public static function query(string $checkoutRequestId): array
    {
        if (!self::configured()) {
            throw new AppException(
                'provider_not_configured',
                'M-Pesa status queries are not configured on this deployment.',
                503
            );
        }

        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
        $payload = [
            'BusinessShortCode' => self::shortcode(),
            'Password' => base64_encode(self::shortcode() . (string) env('DARAJA_PASSKEY', '') . $timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $response = self::post('/mpesa/stkpushquery/v1/query', $payload);
        $data = self::decode($response['body']);

        if ($response['error'] !== null) {
            throw new AppException(
                'provider_unreachable',
                'Could not reach M-Pesa to check the payment status.',
                502,
                ['transport' => true]
            );
        }

        if (self::isProcessingResponse($data)) {
            return ['pending' => true, 'result_code' => null, 'result_desc' => 'The transaction is being processed.', 'http_status' => $response['status']];
        }

        if ($response['status'] >= 500) {
            throw new AppException(
                'provider_query_failed',
                'M-Pesa could not answer the status query right now.',
                502,
                ['http_status' => $response['status']]
            );
        }

        if (!is_array($data)) {
            throw new AppException(
                'provider_bad_response',
                'M-Pesa returned an unreadable status response.',
                502,
                ['http_status' => $response['status']]
            );
        }

        if (isset($data['errorCode']) || (isset($data['ResponseCode']) && (string) $data['ResponseCode'] !== '0')) {
            throw new AppException(
                'provider_query_failed',
                'M-Pesa rejected the status query.',
                502,
                ['provider_message' => self::providerMessage($data)]
            );
        }

        $resultCode = $data['ResultCode'] ?? null;
        if ($resultCode === null) {
            return ['pending' => true, 'result_code' => null, 'result_desc' => 'No result yet.', 'http_status' => $response['status']];
        }

        return [
            'pending' => false,
            'result_code' => (int) $resultCode,
            'result_desc' => self::shortText((string) ($data['ResultDesc'] ?? $data['ResponseDescription'] ?? ''), 255),
            'http_status' => $response['status'],
        ];
    }

    private static function isProcessingResponse(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        $haystack = strtolower((string) ($data['errorMessage'] ?? $data['errorCode'] ?? ''));
        return str_contains($haystack, 'being processed')
            || str_contains($haystack, 'transaction is processing')
            || str_contains($haystack, '500.001.1001');
    }

    /** @return array{status:int, body:string, error:?string, timed_out:bool} */
    private static function post(string $path, array $payload): array
    {
        $send = static fn (string $token): array => HttpClient::postJson(
            self::baseUrl() . $path,
            $payload,
            ['Authorization: Bearer ' . $token],
            20
        );

        $response = $send(self::accessToken());
        if ($response['status'] === 401) {
            self::forgetToken();
            $response = $send(self::accessToken());
        }
        return $response;
    }

    private static function accessToken(): string
    {
        if (!self::configured()) {
            throw new AppException('provider_not_configured', 'M-Pesa is not configured on this deployment.', 503);
        }

        if (self::$token !== null && time() < self::$tokenExpiresAt) {
            return self::$token;
        }

        $cached = self::readTokenCache();
        if ($cached !== null) {
            self::$token = $cached['access_token'];
            self::$tokenExpiresAt = $cached['expires_at'];
            return self::$token;
        }

        $response = HttpClient::request(
            'GET',
            self::baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            [
                'Authorization: Basic ' . base64_encode((string) env('DARAJA_CONSUMER_KEY', '') . ':' . (string) env('DARAJA_CONSUMER_SECRET', '')),
                'Accept: application/json',
            ],
            null,
            15
        );

        if ($response['error'] !== null) {
            throw new AppException('provider_unreachable', 'Could not reach M-Pesa to authenticate.', 502);
        }

        $data = self::decode($response['body']);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($response['status'] !== 200 || $token === '') {
            throw new AppException(
                'provider_auth_failed',
                'M-Pesa rejected the configured credentials. Contact the administrator.',
                502,
                ['http_status' => $response['status']]
            );
        }

        $expiresIn = is_array($data) ? (int) ($data['expires_in'] ?? 3599) : 3599;
        self::$token = $token;
        self::$tokenExpiresAt = time() + max(60, $expiresIn - 60);
        self::writeTokenCache([
            'access_token' => self::$token,
            'expires_at' => self::$tokenExpiresAt,
            'environment' => self::environment(),
            'credential' => self::credentialFingerprint(),
        ]);
        return $token;
    }

    /** @return array{access_token:string, expires_at:int}|null */
    private static function readTokenCache(): ?array
    {
        if (!is_file(self::TOKEN_CACHE)) {
            return null;
        }
        $raw = @file_get_contents(self::TOKEN_CACHE);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)
            || !is_string($data['access_token'] ?? null)
            || !is_int($data['expires_at'] ?? null)
            || ($data['environment'] ?? '') !== self::environment()
            || ($data['credential'] ?? '') !== self::credentialFingerprint()
            || $data['expires_at'] <= time() + 30) {
            return null;
        }
        return ['access_token' => $data['access_token'], 'expires_at' => $data['expires_at']];
    }

    private static function writeTokenCache(array $data): void
    {
        $dir = dirname(self::TOKEN_CACHE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents(self::TOKEN_CACHE, $json, LOCK_EX);
            @chmod(self::TOKEN_CACHE, 0600);
        }
    }

    private static function forgetToken(): void
    {
        self::$token = null;
        self::$tokenExpiresAt = 0;
        if (is_file(self::TOKEN_CACHE)) {
            @unlink(self::TOKEN_CACHE);
        }
    }

    private static function credentialFingerprint(): string
    {
        return substr(hash('sha256', (string) env('DARAJA_CONSUMER_KEY', '') . '|' . self::environment()), 0, 16);
    }

    private static function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function providerMessage(array $data): string
    {
        $message = trim((string) ($data['errorMessage'] ?? $data['ResponseDescription'] ?? ''));
        if ($message === '') {
            return 'M-Pesa rejected the request. Try again, or contact the merchant.';
        }
        return 'M-Pesa rejected the request: ' . self::shortText($message, 200);
    }

    private static function shortText(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_substr($value, 0, $max);
    }
}
