<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;

/**
 * Immutable set of published rates, indexed by date (Y-m-d) and currency code.
 */
final class RateTable
{
    /** @var array<string, array<string, string>> date => currency => rate, sorted by date ascending */
    private readonly array $rates;

    /** @var list<string> sorted ascending */
    private readonly array $dates;

    /** @var array<string, true>|null */
    private ?array $currencies = null;

    /**
     * @param array<string, array<string, string>> $rates date (Y-m-d) => currency => decimal string
     *
     * @throws InvalidSourceData when the data is empty or malformed
     */
    public function __construct(array $rates)
    {
        if ($rates === []) {
            throw new InvalidSourceData('Rate table is empty.');
        }

        foreach ($rates as $date => $byCurrency) {
            self::assertDate((string) $date);
            if ($byCurrency === []) {
                throw new InvalidSourceData(sprintf('No rates published for %s.', $date));
            }
            foreach ($byCurrency as $currency => $value) {
                self::assertCurrency((string) $currency);
                self::assertRate((string) $currency, $value);
            }
        }

        ksort($rates, SORT_STRING);
        $this->rates = $rates;
        $this->dates = array_map('strval', array_keys($rates));
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function toArray(): array
    {
        return $this->rates;
    }

    public function firstDate(): string
    {
        return $this->dates[0];
    }

    public function lastDate(): string
    {
        return $this->dates[count($this->dates) - 1];
    }

    /**
     * Most recent publication date on or before $date, or null if there is none.
     */
    public function dateOnOrBefore(string $date): ?string
    {
        $low = 0;
        $high = count($this->dates) - 1;
        $found = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->dates[$mid] <= $date) {
                $found = $this->dates[$mid];
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string> currency => rate for an exact publication date
     */
    public function on(string $date): array
    {
        return $this->rates[$date] ?? [];
    }

    /**
     * @return array<string, string> date => rate, for dates within [$from, $to] where the currency was published
     */
    public function series(string $currency, string $from, string $to): array
    {
        $series = [];
        foreach ($this->rates as $date => $byCurrency) {
            if ($date < $from) {
                continue;
            }
            if ($date > $to) {
                break;
            }
            if (isset($byCurrency[$currency])) {
                $series[$date] = $byCurrency[$currency];
            }
        }

        return $series;
    }

    public function hasCurrency(string $currency): bool
    {
        if ($this->currencies === null) {
            $this->currencies = [];
            foreach ($this->rates as $byCurrency) {
                foreach ($byCurrency as $code => $_) {
                    $this->currencies[$code] = true;
                }
            }
        }

        return isset($this->currencies[$currency]);
    }

    private static function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidSourceData(sprintf('Invalid date "%s".', $date));
        }
    }

    private static function assertCurrency(string $currency): void
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidSourceData(sprintf('Invalid currency code "%s".', $currency));
        }
    }

    private static function assertRate(string $currency, mixed $value): void
    {
        if (!is_string($value) || preg_match('/^\d+(\.\d+)?$/', $value) !== 1 || (float) $value <= 0.0) {
            throw new InvalidSourceData(sprintf('Invalid rate for %s: %s.', $currency, var_export($value, true)));
        }
    }
}
