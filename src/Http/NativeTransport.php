<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Http;

use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;

/**
 * Dependency-free transport based on PHP stream wrappers.
 */
final class NativeTransport implements Transport
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly string $userAgent = 'codecruz/currency-flow',
    ) {
    }

    public function get(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'user_agent' => $this->userAgent,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $error = null;
        set_error_handler(static function (int $_, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            throw new SourceUnavailable(sprintf('Request to %s failed: %s', $url, $error ?? 'unknown error'));
        }

        $status = self::lastStatusCode($http_response_header);

        if ($status < 200 || $status >= 300) {
            throw new SourceUnavailable(sprintf('Request to %s failed with HTTP status %d.', $url, $status));
        }

        return $body;
    }

    /**
     * Redirects produce several status lines; the last one is the final response.
     *
     * @param list<string> $headers
     */
    private static function lastStatusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
