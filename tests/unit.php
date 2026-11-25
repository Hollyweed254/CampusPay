<?php
declare(strict_types=1);

use App\Services\ApiAuth;
use App\Services\PhoneNumber;
use App\Services\Reference;
use App\Services\SecretBox;
use App\Services\Totp;

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

group('Two-factor authentication (TOTP)');

/** RFC 4648 base32 of the RFC 6238 Appendix B secret, "12345678901234567890". */
const TOTP_RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('base32 encodes the RFC 6238 Appendix B seed', function (): void {
    same(TOTP_RFC_SECRET, Totp::base32Encode('12345678901234567890'));
});
test('base32 decode/encode round-trips', function (): void {
    $bytes = random_bytes(20);
    same($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
});
test('base32 decode rejects invalid characters', function (): void {
    same('', Totp::base32Decode('0189!'));
});

test('codes match the RFC 6238 Appendix B vectors (SHA1, 6 digits)', function (): void {
    $vectors = [
        1 => '287082',          // T = 59
        37037036 => '081804',   // T = 1111111109
        37037037 => '050471',   // T = 1111111111
        41152263 => '005924',   // T = 1234567890
        66666666 => '279037',   // T = 2000000000
        666666666 => '353130',  // T = 20000000000
    ];
    foreach ($vectors as $counter => $expected) {
        same($expected, Totp::codeAt(TOTP_RFC_SECRET, $counter), 'counter ' . $counter);
    }
});

test('verify accepts the current code and reports its counter', function (): void {
    same(1, Totp::verify(TOTP_RFC_SECRET, '287082', 1, 59));
});
test('verify tolerates one step of clock drift', function (): void {
    same(1, Totp::verify(TOTP_RFC_SECRET, '287082', 1, 61));
    same(1, Totp::verify(TOTP_RFC_SECRET, '287082', 1, 30));
});
test('verify rejects wrong, malformed and empty codes', function (): void {
    same(null, Totp::verify(TOTP_RFC_SECRET, '000000', 1, 59));
    same(null, Totp::verify(TOTP_RFC_SECRET, '28708', 1, 59));
    same(null, Totp::verify(TOTP_RFC_SECRET, 'abcdef', 1, 59));
    same(null, Totp::verify(TOTP_RFC_SECRET, '', 1, 59));
    same(null, Totp::verify('', '287082', 1, 59));
});
test('verify refuses a replayed counter via minCounter', function (): void {
    same(null, Totp::verify(TOTP_RFC_SECRET, '287082', 1, 59, 1));
});
test('provisioning URI carries issuer, account, secret and parameters', function (): void {
    $uri = Totp::provisioningUri('CampusPay', 'admin@example.com', TOTP_RFC_SECRET);
    contains('otpauth://totp/CampusPay:admin%40example.com', $uri);
    contains('secret=' . TOTP_RFC_SECRET, $uri);
    contains('algorithm=SHA1', $uri);
    contains('digits=6', $uri);
    contains('period=30', $uri);
});
test('generateSecret produces a usable 32-character base32 seed', function (): void {
    $secret = Totp::generateSecret();
    truthy(preg_match('/^[A-Z2-7]{32}$/', $secret) === 1, 'unexpected seed ' . $secret);
    $code = (string) Totp::codeAt($secret, intdiv(time(), 30));
    truthy(Totp::verify($secret, $code) !== null, 'a freshly generated code should verify');
});
test('verifySealed reads a SecretBox-encrypted seed and blocks replay', function (): void {
    $sealed = SecretBox::encrypt(TOTP_RFC_SECRET);
    $counter = intdiv(time(), 30);
    $code = (string) Totp::codeAt(TOTP_RFC_SECRET, $counter);

    same($counter, Totp::verifySealed($sealed, $code));
    same(null, Totp::verifySealed($sealed, $code, $counter));
    same(null, Totp::verifySealed('', $code));
    same(null, Totp::verifySealed('not-a-box', $code));
});
