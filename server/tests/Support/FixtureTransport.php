<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Tests\Support;

use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\Http\Transport;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;

final class FixtureTransport implements Transport
{
    public bool $down = false;

    public ?string $override = null;

    public function get(string $url): string
    {
        if ($this->down) {
            throw new SourceUnavailable('down');
        }
        if ($this->override !== null) {
            return $this->override;
        }

        $file = match ($url) {
            EcbProvider::URL_LATEST => 'latest.xml',
            EcbProvider::URL_RECENT => 'recent.xml',
            EcbProvider::URL_FULL => 'full.xml',
            default => throw new SourceUnavailable('Unexpected URL ' . $url),
        };

        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/' . $file);
    }
}
