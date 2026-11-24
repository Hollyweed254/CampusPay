<?php
declare(strict_types=1);

/**
 * Minimal CampusPay API client for CampusFind (and any other PHP integration).
 *
 * Standalone: copy this file into the integrating application. It has no
 * dependency on the CampusPay codebase, only on the cURL and JSON extensions.
 *
 *   $campusPay = new CampusPayClient(
 *       'https://pay.example.com',   // deployment base URL, no trailing slash
 *       getenv('CAMPUSPAY_KEY_ID'),
 *       getenv('CAMPUSPAY_SECRET')
 *   );
 *   $request = $campusPay->createPaymentRequest([
 *       'description' => 'Hostel deposit',
 *       'amount' => '2500.00',
 *       'buyerName' => 'Amina W.',
 *       'buyerPhone' => '0712345678',
 *   ], 'hostel-deposit-' . $bookingId);
 */
final class CampusPayApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class CampusPayClient
{
    private string $baseUrl;
    private string $keyId;
    private string $secret;
    private int $timeout;

    public function __construct(string $baseUrl, string $keyId, string $secret, int $timeoutSeconds = 20)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->keyId = $keyId;
        $this->secret = $secret;
        $this->timeout = $timeoutSeconds;
    }

    /** @return array<string, mixed> */
    public function createPaymentRequest(array $input, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey !== null ? ['Idempotency-Key: ' . $idempotencyKey] : [];
        $response = $this->send('POST', '/api/v1/payment-requests', $input, $headers);
        return $response['paymentRequest'];
    }

    /** @return array<string, mixed> */
    public function getPaymentRequest(string $reference): array
    {
        return $this->send('GET', '/api/v1/payment-requests/' . rawurlencode($reference))['paymentRequest'];
    }

    /**
     * @param array{status?:string, q?:string, from?:string, to?:string, limit?:int, offset?:int} $query
     * @return array{paymentRequests: array, meta: array}
     */
    public function listPaymentRequests(array $query = []): array
    {
        $qs = $query === [] ? '' : '?' . http_build_query($query);
        $response = $this->send('GET', '/api/v1/payment-requests' . $qs);
        return ['paymentRequests' => $response['paymentRequests'], 'meta' => $response['meta']];
    }

    /** @return array<string, mixed> */
    public function cancelPaymentRequest(string $reference): array
    {
        return $this->send('POST', '/api/v1/payment-requests/' . rawurlencode($reference) . '/cancel')['paymentRequest'];
    }

    /** @return array<string, mixed> */
    public function getTransaction(string $paymentRequestReference): array
    {
        return $this->send('GET', '/api/v1/transactions/' . rawurlencode($paymentRequestReference))['transaction'];
    }

    /**
     * Verify an incoming webhook. Call this before trusting the payload:
     *
     *   $raw = file_get_contents('php://input');
     *   CampusPayClient::verifyWebhook($raw, $_SERVER, getenv('CAMPUSPAY_SECRET'));
     *
     * @param array<string, mixed> $server Usually $_SERVER
     */
    public static function verifyWebhook(string $rawBody, array $server, string $secret, int $toleranceSeconds = 300): bool
    {
        $timestamp = (string) ($server['HTTP_X_CP_TIMESTAMP'] ?? '');
        $signature = strtolower((string) ($server['HTTP_X_CP_SIGNATURE'] ?? ''));

        if (preg_match('/^\d{10}$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $this->secret);

        $headers = array_merge([
            'X-CP-Key: ' . $this->keyId,
            'X-CP-Timestamp: ' . $timestamp,
            'X-CP-Nonce: ' . $nonce,
            'X-CP-Signature: ' . $signature,
            'Accept: application/json',
        ], $payload !== null ? ['Content-Type: application/json'] : [], $extraHeaders);

        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new CampusPayApiException('client_error', 'Could not initialise HTTP client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($ch) : null;
        curl_close($ch);

        if ($raw === false) {
            throw new CampusPayApiException('network_error', 'CampusPay could not be reached: ' . $error);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new CampusPayApiException('bad_response', 'CampusPay returned an unreadable response.', $status);
        }

        if ($status < 200 || $status >= 300 || ($data['ok'] ?? false) !== true) {
            throw new CampusPayApiException(
                (string) ($data['error'] ?? 'api_error'),
                (string) ($data['message'] ?? 'CampusPay rejected the request.'),
                $status,
                is_array($data['details'] ?? null) ? $data['details'] : []
            );
        }

        return $data;
    }
}
