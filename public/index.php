<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Router;
use App\Core\View;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

send_security_headers();
Auth::start();

set_exception_handler(static function (Throwable $e): void {
    log_exception($e);
    if (str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), app_base_path() . '/api/')) {
        json_out(['ok' => false, 'error' => 'server_error', 'message' => 'Unexpected server error.'], 500);
    }
    http_response_code(500);
    View::render('errors/500', ['title' => 'Server error'], 'layouts/base');
});

// Fatal errors (out of memory, parse of an included file, …) never reach the
// exception handler; capture them so they are logged and alerted too.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    log_exception(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
});

$router = new Router(app_base_path());
require BASE_PATH . '/routes/web.php';
require BASE_PATH . '/routes/api.php';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
