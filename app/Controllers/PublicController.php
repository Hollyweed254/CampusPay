<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

final class PublicController
{
    public function landing(): void
    {
        View::render('public/landing', ['title' => 'Verified M-Pesa payments'], 'layouts/public');
    }

    public function privacy(): void
    {
        View::render('public/privacy', ['title' => 'Privacy notice'], 'layouts/public');
    }

    public function terms(): void
    {
        View::render('public/terms', ['title' => 'Terms of use'], 'layouts/public');
    }
}
