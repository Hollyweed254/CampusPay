<?php
declare(strict_types=1);

use App\Services\ApiAuth;
use App\Services\PhoneNumber;
use App\Services\Reference;
use App\Services\SecretBox;

require_once BASE_PATH . '/clients/campusfind/CampusPayClient.php';

group('Phone numbers');

test('local 07 format normalises', function (): void {
    same('254712345678', PhoneNumber::normalize('0712 345 678'));
});
test('international +254 format normalises', function (): void {
    same('254712345678', PhoneNumber::normalize('+254 712 345 678'));
});
test('254 prefix without plus normalises', function (): void {
    same('254712345678', PhoneNumber::normalize('254-712-345-678'));
});
test('nine digit subscriber number normalises', function (): void {
    same('254712345678', PhoneNumber::normalize('712345678'));
});
test('Safaricom 01 ranges normalise', function (): void {
    same('254112345678', PhoneNumber::normalize('0112 345 678'));
});
test('non-Kenyan and malformed numbers are rejected', function (): void {
    same(null, PhoneNumber::normalize('0812345678'));
    same(null, PhoneNumber::normalize('07123456'));
    same(null, PhoneNumber::normalize(''));
    same(null, PhoneNumber::normalize('abc'));
});
test('display renders +254 groups', function (): void {
    same('+254 712 345 678', PhoneNumber::display('254712345678'));
});

group('Reference');

test('payment references use the CP + date + 16 hex pattern', function (): void {
    $ref = Reference::payment();
    truthy(preg_match('/^CP\d{6}[A-F0-9]{16}$/', $ref) === 1, 'bad reference ' . $ref);
});
test('references are unique across 200 draws', function (): void {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $ref = Reference::payment();
        truthy(!isset($seen[$ref]), 'duplicate reference ' . $ref);
        $seen[$ref] = true;
    }
});

group('SecretBox');

test('encrypt/decrypt roundtrip', function (): void {
    $secret = 'cps_' . bin2hex(random_bytes(24));
    same($secret, SecretBox::decrypt(SecretBox::encrypt($secret)));
});
test('ciphertext is not the plaintext and differs per call', function (): void {
    $first = SecretBox::encrypt('same input');
    $second = SecretBox::encrypt('same input');
    truthy($first !== $second, 'ciphertexts should differ (random IV)');
    truthy(!str_contains($first, 'same input'), 'ciphertext leaks plaintext');
});
test('tampered ciphertext fails closed', function (): void {
    $stored = SecretBox::encrypt('top secret');
    $raw = base64_decode(substr($stored, 3), true);
    truthy($raw !== false && $raw !== '', 'ciphertext should decode');
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';
    throwsCode(fn () => SecretBox::decrypt('v1:' . base64_encode($raw)), 'credential_unreadable');
});
test('garbage input fails with credential_unreadable', function (): void {
    throwsCode(fn () => SecretBox::decrypt('not-a-box'), 'credential_unreadable');
    throwsCode(fn () => SecretBox::decrypt('v1:AAAA'), 'credential_unreadable');
});

group('API signing');

test('canonical string hashes the raw body', function (): void {
    $canonical = ApiAuth::canonicalString('post', '/api/v1/payment-requests', '1763990000', 'nonce1234567890ab', '{"a":1}');
    same(
        "POST\n/api/v1/payment-requests\n1763990000\nnonce1234567890ab\n" . hash('sha256', '{"a":1}'),
        $canonical
    );
});
test('signedPath strips the deployment base path but keeps the query', function (): void {
    $uri = $_SERVER['REQUEST_URI'] ?? null;
    $qs = $_SERVER['QUERY_STRING'] ?? null;
    try {
        $_SERVER['REQUEST_URI'] = app_base_path() . '/api/v1/payment-requests?limit=5&status=pending';
        $_SERVER['QUERY_STRING'] = 'limit=5&status=pending';
        same('/api/v1/payment-requests?limit=5&status=pending', ApiAuth::signedPath());
    } finally {
        $_SERVER['REQUEST_URI'] = $uri ?? '';
        $_SERVER['QUERY_STRING'] = $qs ?? '';
    }
});

group('Webhook verification (client library)');

test('valid signature accepted', function (): void {
    $body = '{"id":"evt_abc","type":"payment.confirmed"}';
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');
    truthy(CampusPayClient::verifyWebhook($body, [
        'HTTP_X_CP_TIMESTAMP' => $timestamp,
        'HTTP_X_CP_SIGNATURE' => $signature,
    ], 'whsec_test'));
});
test('tampered body rejected', function (): void {
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.{"id":"evt_1"}', 'whsec_test');
    truthy(!CampusPayClient::verifyWebhook('{"id":"evt_2"}', [
        'HTTP_X_CP_TIMESTAMP' => $timestamp,
        'HTTP_X_CP_SIGNATURE' => $signature,
    ], 'whsec_test'));
});
test('stale timestamp rejected', function (): void {
    $body = '{"id":"evt_1"}';
    $timestamp = (string) (time() - 301);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');
    truthy(!CampusPayClient::verifyWebhook($body, [
        'HTTP_X_CP_TIMESTAMP' => $timestamp,
        'HTTP_X_CP_SIGNATURE' => $signature,
    ], 'whsec_test'));
});
test('malformed headers rejected', function (): void {
    truthy(!CampusPayClient::verifyWebhook('{}', [], 'whsec_test'));
    truthy(!CampusPayClient::verifyWebhook('{}', [
        'HTTP_X_CP_TIMESTAMP' => (string) time(),
        'HTTP_X_CP_SIGNATURE' => 'not-hex',
    ], 'whsec_test'));
});

group('Formatting helpers');

test('money is rendered with two decimals', function (): void {
    same('KSh 1,234.50', money_ksh('1234.5'));
    same('KSh 0.00', money_ksh(0));
});
test('status chips map known statuses', function (): void {
    same(['Under review', 'cp-chip--info'], status_meta('under_review'));
    same(['Disputed', 'cp-chip--bad'], status_meta('disputed'));
    same(['Recorded', 'cp-chip--info'], status_meta('reported'));
});
test('phone masking keeps the last four digits only', function (): void {
    same('••• 5678', mask_phone('254712345678'));
    same('—', mask_phone(null));
});
