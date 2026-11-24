<?php
declare(strict_types=1);

namespace App\Core;

/** A rule the caller broke, with a stable public code for API clients. */
final class AppException extends \RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
