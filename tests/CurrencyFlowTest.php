<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Tests;

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Exception\InvalidArgument;
use CodeCruz\CurrencyFlow\Exception\RateNotAvailable;
use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\Exception\UnknownCurrency;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;
use CodeCruz\CurrencyFlow\Tests\Support\ArrayCache;
use CodeCruz\CurrencyFlow\Tests\Support\FakeTransport;
use CodeCruz\CurrencyFlow\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyFlowTest extends TestCase
{
    private FakeTransport $transport;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->transport = FakeTransport::ecbFixtures();
        $this->clock = FixedClock::at('2025-03-24 10:00'); // Monday
    }

    private function flow(?ArrayCache $cache = null, int $maxLookbackDays = 7): CurrencyFlow
    {
        return new CurrencyFlow(
            new EcbProvider($this->transport),
            $cache,
            maxLookbackDays: $maxLookbackDays,
            clock: $this->clock,
        );
    }

    public function testLatestRateUsesDailyFeedOnly(): void
    {
        $rate = $this->flow()->rate('usd');

        self::assertSame('EUR', $rate->base);
        self::assertSame('USD', $rate->currency);
        self::assertSame('1.0821', $rate->value);
        self::assertSame('2025-03-21', $rate->date->format('Y-m-d'));
        self::assertNull($rate->requestedDate);
        self::assertFalse($rate->isFallback());
        self::assertSame([EcbProvider::URL_LATEST => 1], $this->transport->calls);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function datedLookups(): iterable
    {
        yield 'exact publication date' => ['USD', '2025-03-20', '2025-03-20', '1.0855'];
        yield 'weekend falls back to Friday' => ['USD', '2025-03-23', '2025-03-21', '1.0821'];
        yield 'gap inside recent feed' => ['GBP', '2025-03-18', '2025-03-14', '0.84000'];
        yield 'older than recent feed uses full history' => ['USD', '2025-02-04', '2025-02-03', '1.0274'];
        yield 'christmas holidays' => ['USD', '2024-12-25', '2024-12-23', '1.0412'];
        yield 'first published day' => ['USD', '1999-01-04', '1999-01-04', '1.1789'];
        yield 'discontinued currency on a date it existed' => ['RUB', '2022-02-28', '2022-02-28', '117.2010'];
    }

    #[DataProvider('datedLookups')]
    public function testRateOnDate(string $currency, string $requested, string $effective, string $value): void
    {
        $rate = $this->flow()->rate($currency, $requested);

        self::assertSame($value, $rate->value);
        self::assertSame($effective, $rate->date->format('Y-m-d'));
        self::assertSame($requested, $rate->requestedDate?->format('Y-m-d'));
        self::assertSame($requested !== $effective, $rate->isFallback());
    }

    public function testAcceptsDateTimeObjects(): void
    {
        $rate = $this->flow()->rate('USD', new \DateTimeImmutable('2025-03-20'));

        self::assertSame('1.0855', $rate->value);
    }

    public function testBaseCurrencyIsAlwaysOne(): void
    {
        $rate = $this->flow()->rate('EUR', '2025-03-22');

        self::assertSame('1', $rate->value);
        self::assertSame('2025-03-21', $rate->date->format('Y-m-d'));
    }

    public function testLookbackLimitIsEnforced(): void
    {
        $this->expectException(RateNotAvailable::class);
        $this->expectExceptionMessage('2025-03-14, 4 days earlier (limit: 3)');

        $this->flow(maxLookbackDays: 3)->rate('USD', '2025-03-18');
    }

    public function testLongGapInHistoryIsNotAvailable(): void
    {
        $this->expectException(RateNotAvailable::class);

        $this->flow()->rate('USD', '2022-03-15');
    }

    public function testDateBeforeHistoryIsNotAvailable(): void
    {
        $this->expectException(RateNotAvailable::class);
        $this->expectExceptionMessage('data starts on 1999-01-04');

        $this->flow()->rate('USD', '1998-12-31');
    }

    public function testDiscontinuedCurrencyIsNotAvailableButNotUnknown(): void
    {
        $this->expectException(RateNotAvailable::class);

        $this->flow()->rate('RUB', '2024-12-23');
    }

    public function testUnknownCurrency(): void
    {
        $this->expectException(UnknownCurrency::class);

        $this->flow()->rate('XYZ');
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'short code' => ['US', null];
        yield 'numeric code' => ['840', null];
        yield 'legacy date format' => ['USD', '23-03-2025'];
        yield 'impossible date' => ['USD', '2025-02-30'];
        yield 'future date' => ['USD', '2025-03-25'];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArguments(string $currency, ?string $date): void
    {
        $this->expectException(InvalidArgument::class);

        $this->flow()->rate($currency, $date);
    }

    public function testAllRatesForDate(): void
    {
        $rates = $this->flow()->rates('2025-03-22');

        self::assertSame(['GBP', 'JPY', 'USD'], array_keys($rates));
        self::assertSame('161.53', $rates['JPY']->value);
        self::assertSame('2025-03-21', $rates['JPY']->date->format('Y-m-d'));
    }

    public function testHistoryReturnsPublicationDatesOldestFirst(): void
    {
        $history = $this->flow()->history('USD', '2025-03-14', '2025-03-21');

        self::assertSame(
            ['2025-03-14' => '1.0880', '2025-03-20' => '1.0855', '2025-03-21' => '1.0821'],
            array_combine(
                array_map(static fn ($r) => $r->date->format('Y-m-d'), $history),
                array_map(static fn ($r) => $r->value, $history),
            ),
        );
    }

    public function testHistoryRejectsReversedRange(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->flow()->history('USD', '2025-03-21', '2025-03-14');
    }

    public function testHistoryRejectsUnknownCurrency(): void
    {
        $this->expectException(UnknownCurrency::class);

        $this->flow()->history('XYZ', '2025-03-14', '2025-03-21');
    }

    public function testCurrencies(): void
    {
        self::assertSame(['GBP', 'JPY', 'USD'], $this->flow()->currencies());
    }

    public function testRateSerializesToArray(): void
    {
        self::assertSame(
            [
                'base' => 'EUR',
                'currency' => 'USD',
                'rate' => '1.0821',
                'date' => '2025-03-21',
                'requested_date' => '2025-03-23',
                'source' => 'ECB',
            ],
            $this->flow()->rate('USD', '2025-03-23')->toArray(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string|null, string, string}>
     */
    public static function crossRates(): iterable
    {
        // currency, base, date, expected value, expected publication date
        yield 'GBP in USD' => ['GBP', 'USD', null, '0.773034', '2025-03-21'];
        yield 'EUR in USD' => ['EUR', 'USD', null, '0.924129', '2025-03-21'];
        yield 'JPY in USD keeps 6 significant digits' => ['JPY', 'usd', null, '149.275', '2025-03-21'];
        yield 'same currency' => ['USD', 'USD', null, '1', '2025-03-21'];
        yield 'EUR base is the published value' => ['USD', 'EUR', null, '1.0821', '2025-03-21'];
        yield 'weekend uses previous publication' => ['GBP', 'USD', '2025-03-16', '0.772059', '2025-03-14'];
    }

    #[DataProvider('crossRates')]
    public function testCrossRates(string $currency, string $base, ?string $date, string $value, string $effective): void
    {
        $rate = $this->flow()->rate($currency, $date, $base);

        self::assertSame(strtoupper($base), $rate->base);
        self::assertSame($currency, $rate->currency);
        self::assertSame($value, $rate->value);
        self::assertSame($effective, $rate->date->format('Y-m-d'));
    }

    public function testUnknownCrossBase(): void
    {
        $this->expectException(UnknownCurrency::class);

        $this->flow()->rate('USD', null, 'XYZ');
    }

    public function testAllRatesInAnotherBaseIncludeTheEuro(): void
    {
        $rates = $this->flow()->rates(null, 'USD');

        self::assertSame(['EUR', 'GBP', 'JPY'], array_keys($rates));
        self::assertSame('0.924129', $rates['EUR']->value);
        self::assertSame('USD', $rates['GBP']->base);
    }

    /**
     * @return iterable<string, array{int|float|string, string, string, int, string}>
     */
    public static function conversions(): iterable
    {
        yield 'between two quoted currencies' => [100, 'USD', 'GBP', 2, '77.30'];
        yield 'from the euro' => [100, 'EUR', 'USD', 2, '108.21'];
        yield 'to the euro' => ['108.21', 'usd', 'eur', 2, '100.00'];
        yield 'decimal string amount' => ['1234.5', 'EUR', 'GBP', 2, '1032.66'];
        yield 'zero decimals' => [10, 'USD', 'JPY', 0, '1493'];
        yield 'negative amount' => [-100, 'USD', 'GBP', 2, '-77.30'];
        yield 'same currency' => [42.5, 'GBP', 'GBP', 2, '42.50'];
    }

    #[DataProvider('conversions')]
    public function testConvert(int|float|string $amount, string $from, string $to, int $precision, string $expected): void
    {
        $conversion = $this->flow()->convert($amount, $from, $to, precision: $precision);

        self::assertSame($expected, $conversion->result);
        self::assertSame(strtoupper($from), $conversion->rate->base);
        self::assertSame(strtoupper($to), $conversion->rate->currency);
    }

    public function testConversionOnDateSerializes(): void
    {
        self::assertSame(
            [
                'amount' => '100',
                'from' => 'USD',
                'to' => 'GBP',
                'result' => '77.21',
                'rate' => '0.772059',
                'date' => '2025-03-14',
                'requested_date' => '2025-03-16',
                'source' => 'ECB',
            ],
            $this->flow()->convert(100, 'USD', 'GBP', '2025-03-16')->toArray(),
        );
    }

    /**
     * @return iterable<string, array{int|float|string, int}>
     */
    public static function invalidConversions(): iterable
    {
        yield 'non numeric amount' => ['abc', 2];
        yield 'infinite amount' => [INF, 2];
        yield 'negative precision' => [100, -1];
        yield 'excessive precision' => [100, 11];
    }

    #[DataProvider('invalidConversions')]
    public function testInvalidConversions(int|float|string $amount, int $precision): void
    {
        $this->expectException(InvalidArgument::class);

        $this->flow()->convert($amount, 'USD', 'GBP', precision: $precision);
    }

    public function testTablesAreFetchedOncePerInstance(): void
    {
        $flow = $this->flow();
        $flow->rate('USD');
        $flow->rate('GBP');
        $flow->currencies();

        self::assertSame(1, $this->transport->totalCalls());
    }

    public function testFreshCacheIsSharedBetweenInstances(): void
    {
        $cache = new ArrayCache();
        $this->flow($cache)->rate('USD');
        $this->clock->advance('+30 minutes');
        $this->flow($cache)->rate('USD');

        self::assertSame(1, $this->transport->totalCalls());
    }

    public function testExpiredCacheIsRefreshed(): void
    {
        $cache = new ArrayCache();
        $this->flow($cache)->rate('USD');
        $this->clock->advance('+2 hours');
        $this->flow($cache)->rate('USD');

        self::assertSame(2, $this->transport->totalCalls());
    }

    public function testStaleCacheIsServedWhenSourceIsDown(): void
    {
        $cache = new ArrayCache();
        $this->flow($cache)->rate('USD');
        $this->clock->advance('+2 hours');
        $this->transport->down = true;

        self::assertSame('1.0821', $this->flow($cache)->rate('USD')->value);
    }

    public function testStaleCacheIsServedWhenSourceReturnsGarbage(): void
    {
        $cache = new ArrayCache();
        $this->flow($cache)->rate('USD');
        $this->clock->advance('+2 hours');
        $this->transport->respond(EcbProvider::URL_LATEST, '<html>Maintenance</html>');

        self::assertSame('1.0821', $this->flow($cache)->rate('USD')->value);
    }

    public function testSourceErrorsPropagateWithoutCache(): void
    {
        $this->transport->down = true;

        $this->expectException(SourceUnavailable::class);

        $this->flow()->rate('USD');
    }

    public function testCorruptCacheEntryIsIgnored(): void
    {
        $cache = new ArrayCache();
        $cache->set('currency-flow.ecb.latest', ['fetched_at' => time(), 'rates' => ['nope' => []]]);

        self::assertSame('1.0821', $this->flow($cache)->rate('USD')->value);
        self::assertSame(1, $this->transport->totalCalls());
    }
}
