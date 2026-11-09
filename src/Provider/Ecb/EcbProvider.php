<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Provider\Ecb;

use CodeCruz\CurrencyFlow\Coverage;
use CodeCruz\CurrencyFlow\Http\NativeTransport;
use CodeCruz\CurrencyFlow\Http\Transport;
use CodeCruz\CurrencyFlow\Provider\RateProvider;
use CodeCruz\CurrencyFlow\RateTable;

/**
 * Euro foreign exchange reference rates published by the European Central Bank.
 *
 * @see https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html
 */
final class EcbProvider implements RateProvider
{
    public const URL_LATEST = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    public const URL_RECENT = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    public const URL_FULL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';

    private readonly Transport $transport;

    private readonly EcbXmlParser $parser;

    public function __construct(?Transport $transport = null, ?EcbXmlParser $parser = null)
    {
        $this->transport = $transport ?? new NativeTransport();
        $this->parser = $parser ?? new EcbXmlParser();
    }

    public function name(): string
    {
        return 'ECB';
    }

    public function baseCurrency(): string
    {
        return 'EUR';
    }

    public function fetch(Coverage $coverage): RateTable
    {
        $url = match ($coverage) {
            Coverage::Latest => self::URL_LATEST,
            Coverage::Recent => self::URL_RECENT,
            Coverage::Full => self::URL_FULL,
        };

        return $this->parser->parse($this->transport->get($url));
    }
}
