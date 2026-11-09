<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Tests\Support;

use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\Http\Transport;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;

final class FakeTransport implements Transport
{
    /** @var array<string, int> */
    public array $calls = [];

    public bool $down = false;

    /**
     * @param array<string, string> $responses url => body
     */
    public function __construct(private array $responses)
    {
    }

    public static function ecbFixtures(): self
    {
        $dir = dirname(__DIR__) . '/Fixtures';

        return new self([
            EcbProvider::URL_LATEST => (string) file_get_contents($dir . '/latest.xml'),
            EcbProvider::URL_RECENT => (string) file_get_contents($dir . '/recent.xml'),
            EcbProvider::URL_FULL => (string) file_get_contents($dir . '/full.xml'),
        ]);
    }

    public function respond(string $url, string $body): void
    {
        $this->responses[$url] = $body;
    }

    public function get(string $url): string
    {
        $this->calls[$url] = ($this->calls[$url] ?? 0) + 1;

        if ($this->down || !isset($this->responses[$url])) {
            throw new SourceUnavailable('Unavailable: ' . $url);
        }

        return $this->responses[$url];
    }

    public function totalCalls(): int
    {
        return array_sum($this->calls);
    }
}
