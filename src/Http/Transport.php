<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Http;

use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;

/**
 * Minimal HTTP abstraction so the library has no hard dependency on an HTTP client.
 */
interface Transport
{
    /**
     * Performs a GET request and returns the response body.
     *
     * @throws SourceUnavailable on network errors or non-2xx responses
     */
    public function get(string $url): string;
}
