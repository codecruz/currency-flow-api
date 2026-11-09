<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

use CodeCruz\CurrencyFlow\Exception\InvalidArgument;
use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;
use CodeCruz\CurrencyFlow\Exception\RateNotAvailable;
use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\Exception\UnknownCurrency;
use CodeCruz\CurrencyFlow\Http\Transport;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;
use CodeCruz\CurrencyFlow\Provider\RateProvider;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Entry point of the library.
 *
 *     $flow = CurrencyFlow::ecb(cache: $psr16Cache);
 *     $flow->rate('USD');                // latest published rate
 *     $flow->rate('USD', '2025-03-23');  // Sunday: resolves to Friday 2025-03-21
 */
final class CurrencyFlow
{
    /** Requests younger than this are served from the provider's "recent" feed. */
    private const RECENT_WINDOW_DAYS = 80;

    /** Dates are interpreted in the ECB's time zone. */
    private const TIMEZONE = 'Europe/Berlin';

    private readonly ClockInterface $clock;

    /** @var array<string, RateTable> */
    private array $tables = [];

    /**
     * @param int $cacheTtl        Seconds a cached table is considered fresh.
     * @param int $staleTtl        Seconds a cached table is kept to be served when the source is down.
     * @param int $maxLookbackDays Maximum distance between the requested date and the publication used.
     */
    public function __construct(
        private readonly RateProvider $provider,
        private readonly ?CacheInterface $cache = null,
        private readonly int $cacheTtl = 3600,
        private readonly int $staleTtl = 7 * 86400,
        private readonly int $maxLookbackDays = 7,
        ?ClockInterface $clock = null,
    ) {
        if ($cacheTtl < 0 || $staleTtl < $cacheTtl) {
            throw new InvalidArgument('Expected 0 <= cacheTtl <= staleTtl.');
        }
        if ($maxLookbackDays < 0) {
            throw new InvalidArgument('maxLookbackDays must not be negative.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Shortcut for the European Central Bank provider.
     */
    public static function ecb(
        ?CacheInterface $cache = null,
        ?Transport $transport = null,
        ?ClockInterface $clock = null,
    ): self {
        return new self(new EcbProvider($transport), $cache, clock: $clock);
    }

    /**
     * Rate for one currency. Without a date, the latest publication is used.
     * On weekends and holidays, the closest earlier publication is returned
     * (see {@see Rate::isFallback()}).
     *
     * With a $base other than the provider's (EUR for the ECB), a cross rate is
     * derived from both published rates and rounded to 6 significant digits.
     *
     * @throws UnknownCurrency|RateNotAvailable|InvalidArgument|SourceUnavailable|InvalidSourceData
     */
    public function rate(string $currency, \DateTimeInterface|string|null $date = null, ?string $base = null): Rate
    {
        $currency = $this->normalizeCurrency($currency);
        $base = $base === null ? $this->provider->baseCurrency() : $this->normalizeCurrency($base);
        $requested = $date === null ? null : $this->normalizeDate($date);
        [$table, $effective] = $this->publication($requested);

        $value = match (true) {
            $currency === $base => '1',
            $base === $this->provider->baseCurrency() => $this->quote($table, $effective, $currency),
            default => self::formatDerived(
                (float) $this->quote($table, $effective, $currency) / (float) $this->quote($table, $effective, $base),
            ),
        };

        return $this->makeRate($currency, $value, $effective, $requested, $base);
    }

    /**
     * Every rate published for a date (or the latest one), keyed by currency.
     * The base currency itself is not included.
     *
     * With a $base other than the provider's, every rate (including the provider's
     * own base currency) is a derived cross rate, rounded to 6 significant digits.
     *
     * @return array<string, Rate>
     *
     * @throws UnknownCurrency|RateNotAvailable|InvalidArgument|SourceUnavailable|InvalidSourceData
     */
    public function rates(\DateTimeInterface|string|null $date = null, ?string $base = null): array
    {
        $providerBase = $this->provider->baseCurrency();
        $base = $base === null ? $providerBase : $this->normalizeCurrency($base);
        $requested = $date === null ? null : $this->normalizeDate($date);
        [$table, $effective] = $this->publication($requested);

        $published = $table->on($effective);
        $baseValue = (float) $this->quote($table, $effective, $base);

        $rates = [];
        foreach ([$providerBase => '1'] + $published as $currency => $value) {
            if ($currency === $base) {
                continue;
            }
            $rates[$currency] = $this->makeRate(
                $currency,
                $base === $providerBase ? $value : self::formatDerived((float) $value / $baseValue),
                $effective,
                $requested,
                $base,
            );
        }
        ksort($rates);

        return $rates;
    }

    /**
     * Converts an amount between any two published currencies (or the base currency),
     * using the rates of the given date (latest publication if omitted).
     *
     * The result is computed from the published rates (not the rounded cross rate)
     * and rounded half away from zero to $precision decimals.
     *
     * @throws UnknownCurrency|RateNotAvailable|InvalidArgument|SourceUnavailable|InvalidSourceData
     */
    public function convert(
        int|float|string $amount,
        string $from,
        string $to,
        \DateTimeInterface|string|null $date = null,
        int $precision = 2,
    ): Conversion {
        if (!is_numeric($amount) || !is_finite((float) $amount)) {
            throw new InvalidArgument(sprintf('Amount "%s" is not a finite number.', $amount));
        }
        if ($precision < 0 || $precision > 10) {
            throw new InvalidArgument('Precision must be between 0 and 10.');
        }

        $from = $this->normalizeCurrency($from);
        $to = $this->normalizeCurrency($to);
        $requested = $date === null ? null : $this->normalizeDate($date);
        [$table, $effective] = $this->publication($requested);

        $ratio = (float) $this->quote($table, $effective, $to) / (float) $this->quote($table, $effective, $from);
        $result = number_format(round((float) $amount * $ratio, $precision), $precision, '.', '');

        return new Conversion(
            amount: (string) $amount,
            from: $from,
            to: $to,
            result: $result,
            rate: $this->rate($to, $requested, $from),
        );
    }

    /**
     * Published rates for a currency between two dates (inclusive), oldest first.
     * Only actual publication dates are returned; weekends and holidays are skipped.
     *
     * @return list<Rate>
     *
     * @throws UnknownCurrency|InvalidArgument|SourceUnavailable|InvalidSourceData
     */
    public function history(string $currency, \DateTimeInterface|string $from, \DateTimeInterface|string $to): array
    {
        $currency = $this->normalizeCurrency($currency);
        $from = $this->normalizeDate($from);
        $to = $this->normalizeDate($to);

        if ($from > $to) {
            throw new InvalidArgument(sprintf('"from" (%s) must not be after "to" (%s).', $from, $to));
        }

        $table = $this->tableCovering($from);

        if ($currency === $this->provider->baseCurrency()) {
            $dates = array_keys(array_filter(
                $table->toArray(),
                static fn (string $date): bool => $date >= $from && $date <= $to,
                ARRAY_FILTER_USE_KEY,
            ));
            $series = array_fill_keys($dates, '1');
        } else {
            if (!$table->hasCurrency($currency)) {
                throw UnknownCurrency::code($currency, $this->provider->name());
            }
            $series = $table->series($currency, $from, $to);
        }

        $history = [];
        foreach ($series as $day => $value) {
            $history[] = $this->makeRate($currency, $value, (string) $day, (string) $day);
        }

        return $history;
    }

    /**
     * Currency codes in the latest publication, sorted alphabetically (base currency excluded).
     *
     * @return list<string>
     *
     * @throws SourceUnavailable|InvalidSourceData
     */
    public function currencies(): array
    {
        $table = $this->table(Coverage::Latest);
        $codes = array_map('strval', array_keys($table->on($table->lastDate())));
        sort($codes);

        return $codes;
    }

    public function provider(): RateProvider
    {
        return $this->provider;
    }

    private function tableCovering(string $date): RateTable
    {
        $recentFrom = $this->today()->modify(sprintf('-%d days', self::RECENT_WINDOW_DAYS))->format('Y-m-d');

        if ($date >= $recentFrom) {
            $recent = $this->table(Coverage::Recent);
            if ($recent->firstDate() <= $date) {
                return $recent;
            }
        }

        return $this->table(Coverage::Full);
    }

    private function resolveDate(RateTable $table, string $requested): string
    {
        $effective = $table->dateOnOrBefore($requested);

        if ($effective === null) {
            throw new RateNotAvailable(sprintf(
                'No %s rates on or before %s; data starts on %s.',
                $this->provider->name(),
                $requested,
                $table->firstDate(),
            ));
        }

        $gap = (int) (new \DateTimeImmutable($effective))->diff(new \DateTimeImmutable($requested))->days;
        if ($gap > $this->maxLookbackDays) {
            throw new RateNotAvailable(sprintf(
                'Closest %s publication to %s is %s, %d days earlier (limit: %d).',
                $this->provider->name(),
                $requested,
                $effective,
                $gap,
                $this->maxLookbackDays,
            ));
        }

        return $effective;
    }

    /**
     * Loads a table once per instance, using the cache when available.
     * If the source fails, a stale cached copy (up to $staleTtl old) is served instead.
     */
    private function table(Coverage $coverage): RateTable
    {
        if (isset($this->tables[$coverage->value])) {
            return $this->tables[$coverage->value];
        }

        $key = sprintf('currency-flow.%s.%s', strtolower($this->provider->name()), $coverage->value);
        $now = $this->clock->now()->getTimestamp();
        $cached = $this->readCache($key);

        if ($cached !== null && $now - $cached['fetched_at'] < $this->cacheTtl) {
            return $this->tables[$coverage->value] = $cached['table'];
        }

        try {
            $table = $this->provider->fetch($coverage);
        } catch (SourceUnavailable|InvalidSourceData $e) {
            if ($cached !== null) {
                return $this->tables[$coverage->value] = $cached['table'];
            }
            throw $e;
        }

        $this->cache?->set($key, ['fetched_at' => $now, 'rates' => $table->toArray()], $this->staleTtl);

        return $this->tables[$coverage->value] = $table;
    }

    /**
     * @return array{fetched_at: int, table: RateTable}|null
     */
    private function readCache(string $key): ?array
    {
        $entry = $this->cache?->get($key);

        if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['rates'] ?? null)) {
            return null;
        }

        try {
            /** @var array<string, array<string, string>> $rates */
            $rates = $entry['rates'];

            return ['fetched_at' => $entry['fetched_at'], 'table' => new RateTable($rates)];
        } catch (InvalidSourceData) {
            return null;
        }
    }

    /**
     * Table and publication date to use for a requested date (latest when null).
     *
     * @return array{RateTable, string}
     */
    private function publication(?string $requested): array
    {
        if ($requested === null) {
            $table = $this->table(Coverage::Latest);

            return [$table, $table->lastDate()];
        }

        $table = $this->tableCovering($requested);

        return [$table, $this->resolveDate($table, $requested)];
    }

    /**
     * Published value of 1 provider-base unit in $currency on an exact publication date.
     */
    private function quote(RateTable $table, string $date, string $currency): string
    {
        if ($currency === $this->provider->baseCurrency()) {
            return '1';
        }

        $value = $table->on($date)[$currency] ?? null;
        if ($value !== null) {
            return $value;
        }

        if (!$table->hasCurrency($currency)) {
            throw UnknownCurrency::code($currency, $this->provider->name());
        }

        throw new RateNotAvailable(sprintf('%s did not publish a %s rate on %s.', $this->provider->name(), $currency, $date));
    }

    /**
     * Derived rates carry 6 significant digits: the ECB publishes 5, so more would be false precision.
     */
    private static function formatDerived(float $value): string
    {
        $decimals = max(0, 5 - (int) floor(log10($value)));

        return number_format($value, $decimals, '.', '');
    }

    private function makeRate(string $currency, string $value, string $date, ?string $requested, ?string $base = null): Rate
    {
        return new Rate(
            base: $base ?? $this->provider->baseCurrency(),
            currency: $currency,
            value: $value,
            date: $this->toDate($date),
            requestedDate: $requested === null ? null : $this->toDate($requested),
            source: $this->provider->name(),
        );
    }

    private function normalizeCurrency(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgument(sprintf('"%s" is not a valid ISO 4217 currency code.', $currency));
        }

        return $code;
    }

    private function normalizeDate(\DateTimeInterface|string $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            $day = $date->format('Y-m-d');
        } else {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgument(sprintf('Invalid date "%s"; expected YYYY-MM-DD.', $date));
            }
            $day = $date;
        }

        if ($day > $this->today()->format('Y-m-d')) {
            throw new InvalidArgument(sprintf('Date %s is in the future.', $day));
        }

        return $day;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    private function toDate(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone(self::TIMEZONE));
    }
}
