<?php
declare(strict_types=1);

/**
 * Micro test runner: plain PHP, no dependencies.
 *
 *   php tests/run.php
 *
 * Exits 0 when everything passes, 1 otherwise. Database tests create their
 * own application rows and remove them again, so they are safe to run on a
 * development database.
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

final class TestFail extends RuntimeException
{
}

$GLOBALS['t_passed'] = 0;
$GLOBALS['t_failed'] = 0;
$GLOBALS['t_group'] = '';

function group(string $name): void
{
    $GLOBALS['t_group'] = $name;
    echo PHP_EOL . '== ' . $name . ' ==' . PHP_EOL;
}

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['t_passed']++;
        echo '  PASS  ' . $name . PHP_EOL;
    } catch (TestFail $e) {
        $GLOBALS['t_failed']++;
        echo '  FAIL  ' . $name . ' — ' . $e->getMessage() . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['t_failed']++;
        echo '  FAIL  ' . $name . ' — unexpected ' . $e::class . ': ' . $e->getMessage() . PHP_EOL;
    }
}

function same(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected !== $actual) {
        throw new TestFail(
            ($note === '' ? '' : $note . ': ')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function truthy(bool $condition, string $note = 'condition is false'): void
{
    if (!$condition) {
        throw new TestFail($note);
    }
}

function contains(string $needle, string $haystack, string $note = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFail(($note === '' ? '' : $note . ': ') . 'missing "' . $needle . '"');
    }
}

/** @param callable():mixed $fn */
function throwsCode(callable $fn, string $publicCode, string $note = ''): void
{
    try {
        $fn();
    } catch (App\Core\AppException $e) {
        if ($e->publicCode !== $publicCode) {
            throw new TestFail('expected code ' . $publicCode . ', got ' . $e->publicCode);
        }
        return;
    }
    throw new TestFail(($note === '' ? '' : $note . ': ') . 'no AppException with code ' . $publicCode);
}

require __DIR__ . '/unit.php';
require __DIR__ . '/database.php';

echo PHP_EOL . sprintf(
    '%d passed, %d failed' . PHP_EOL,
    $GLOBALS['t_passed'],
    $GLOBALS['t_failed']
);
exit($GLOBALS['t_failed'] === 0 ? 0 : 1);
