<?php
declare(strict_types=1);

namespace App\Validators;

final class Validator
{
    public static function required(?string $value): ?string
    {
        return ($value === null || trim($value) === '') ? 'This field is required.' : null;
    }

    public static function email(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return 'Email is required.';
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? 'Enter a valid email address.' : null;
    }

    public static function minLength(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return 'This field is required.';
        }
        return mb_strlen($value) < $length ? "Must be at least $length characters." : null;
    }
}
