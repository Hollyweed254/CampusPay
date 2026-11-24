<?php
declare(strict_types=1);

namespace App\Services;

/** Kenyan mobile numbers normalised to 2547XXXXXXXX / 2541XXXXXXXX. */
final class PhoneNumber
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]+/', '', trim($input)) ?? '';
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^\d{9,12}$/', $digits) !== 1) {
            return null;
        }

        if (str_starts_with($digits, '254')) {
            $local = substr($digits, 3);
        } else {
            $local = $digits;
        }

        if (strlen($local) === 10 && $local[0] === '0') {
            $local = substr($local, 1);
        }

        if (strlen($local) !== 9 || !in_array($local[0], ['7', '1'], true)) {
            return null;
        }

        return '254' . $local;
    }

    public static function display(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '254')) {
            return '+254 ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3) . ' ' . substr($digits, 9, 3);
        }
        return $msisdn;
    }
}
