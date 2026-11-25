<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Zero-dependency error alerting.
 *
 * The permanent record stays in storage/logs/app.log; this only fans a short
 * summary out to a webhook (Slack/Discord/generic JSON) and/or an email
 * address, so you hear about a break before a user complains. Alerts for the
 * same error are throttled so an outage cannot become a flood.
 */
final class ErrorReporter
{
    private const THROTTLE_SECONDS = 300;

    public static function alert(string $summary, string $detail = ''): void
    {
        $webhook = (string) env('ERROR_ALERT_WEBHOOK', '');
        $email = (string) env('ERROR_ALERT_EMAIL', '');
        if ($webhook === '' && $email === '') {
            return;
        }
        if (self::throttled($summary)) {
            return;
        }

        if ($webhook !== '') {
            self::postJson($webhook, ['text' => 'CampusPay error: ' . $summary . ($detail === '' ? '' : "\n" . $detail)]);
        }

        if ($email !== '') {
            $host = (string) (parse_url((string) env('APP_URL', ''), PHP_URL_HOST)
                ?: ($_SERVER['HTTP_HOST'] ?? 'campuspay.local'));
            @mail(
                $email,
                'CampusPay error: ' . $summary,
                $summary . "\n\n" . $detail . "\n\n" . gmdate('c'),
                'From: campuspay@' . $host
            );
        }
    }

    /** True when an identical summary was already alerted inside the window. */
    private static function throttled(string $summary): bool
    {
        $dir = BASE_PATH . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $path = $dir . '/alert-' . substr(hash('sha256', $summary), 0, 16);
        $last = is_file($path) ? (int) @file_get_contents($path) : 0;
        if ($last > time() - self::THROTTLE_SECONDS) {
            return true;
        }
        @file_put_contents($path, (string) time(), LOCK_EX);
        return false;
    }

    private static function postJson(string $url, array $payload): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($url, false, $context);
    }
}
