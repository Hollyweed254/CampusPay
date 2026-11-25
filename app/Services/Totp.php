<?php
declare(strict_types=1);

namespace App\Services;

/**
 * RFC 6238 time-based one-time passwords: HMAC-SHA1, 6 digits, 30-second step.
 * Pure PHP, no dependencies, so it works with any authenticator app
 * (Google Authenticator, Authy, 1Password, …) via the otpauth:// URI.
 *
 * Verified against the RFC 6238 Appendix B test vectors in tests/unit.php.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const DIGITS = 6;
    public const PERIOD = 30;

    /** A fresh base32 secret (20 random bytes = 160 bits). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** otpauth:// URI for manual entry or for the authenticator app to scan. */
    public static function provisioningUri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Verify a submitted code, tolerating +/- $window steps of clock drift.
     * Returns the matched time counter, or null when nothing matches.
     *
     * When $minCounter is supplied, that counter and anything older is refused,
     * which stops an already-accepted code being replayed inside its window.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null, ?int $minCounter = null): ?int
    {
        $code = preg_replace('/[\s-]+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return null;
        }

        $counter = intdiv($now ?? time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $counter + $i;
            if ($candidate < 0 || ($minCounter !== null && $candidate <= $minCounter)) {
                continue;
            }
            if (hash_equals(self::hotp($key, $candidate), $code)) {
                return $candidate;
            }
        }
        return null;
    }

    /** Code for an explicit counter; used by the tests. */
    public static function codeAt(string $secret, int $counter): ?string
    {
        $key = self::base32Decode($secret);
        return $key === '' ? null : self::hotp($key, $counter);
    }

    /**
     * Verify a code against a SecretBox-encrypted seed as stored on the
     * administrator row. Returns the matched counter, or null when the code
     * is wrong or the stored seed cannot be read (e.g. APP_KEY was rotated).
     */
    public static function verifySealed(string $sealed, string $code, ?int $minCounter = null): ?int
    {
        if ($sealed === '') {
            return null;
        }
        try {
            $secret = SecretBox::decrypt($sealed);
        } catch (\Throwable $e) {
            log_exception($e);
            return null;
        }
        return self::verify($secret, $code, 1, null, $minCounter);
    }

    // -- RFC 4226 HOTP --------------------------------------------------------

    private static function hotp(string $key, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // -- Base32 (RFC 4648, padding omitted) ----------------------------------

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $encoded;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper(str_replace([' ', '-'], '', $secret)), '=');
        if ($secret === '') {
            return '';
        }

        $bits = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $index = strpos(self::ALPHABET, $secret[$i]);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }
        return $decoded;
    }
}
