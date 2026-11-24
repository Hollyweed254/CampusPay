<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal cURL wrapper for provider calls. No redirects (a redirect would let
 * a compromised endpoint move the request elsewhere), TLS verified, and a
 * hard timeout so a stalled provider cannot hold a PHP worker indefinitely.
 */
final class HttpClient
{
    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, error:?string, timed_out:bool}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 15
    ): array {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Only HTTP(S) provider URLs are allowed.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed', 'timed_out' => false];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_merge(['Expect:'], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CampusPay/1.0 (+PHP)',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = $response === false ? curl_error($ch) : null;
        $timedOut = $response === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $response === false ? '' : (string) $response,
            'error' => $error,
            'timed_out' => $timedOut,
        ];
    }

    /** @return array{status:int, body:string, error:?string, timed_out:bool} */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 15): array
    {
        return self::request(
            'POST',
            $url,
            array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            $timeoutSeconds
        );
    }
}
