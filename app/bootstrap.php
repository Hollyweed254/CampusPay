<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require BASE_PATH . '/app/Core/Env.php';
App\Core\Env::load(BASE_PATH . '/.env');

// Timestamps are stored and rendered as UTC everywhere; pin PHP to match.
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Core/helpers.php';
require BASE_PATH . '/app/Core/Database.php';
require BASE_PATH . '/app/Core/View.php';
require BASE_PATH . '/app/Core/Router.php';
require BASE_PATH . '/app/Core/Csrf.php';
require BASE_PATH . '/app/Core/Auth.php';
require BASE_PATH . '/app/Middleware/LoginThrottle.php';
require BASE_PATH . '/app/Services/AuditLogger.php';
require BASE_PATH . '/app/Repositories/AdminRepository.php';
require BASE_PATH . '/app/Validators/Validator.php';
