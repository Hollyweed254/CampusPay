<?php
declare(strict_types=1);

namespace App\Services;

final class Reference
{
    public static function payment(): string
    {
        return 'CP' . gmdate('ymd') . strtoupper(bin2hex(random_bytes(8)));
    }

    public static function slug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
        if ($slug === '') {
            $slug = 'app-' . strtolower(bin2hex(random_bytes(4)));
        }
        return substr($slug, 0, 60);
    }
}
