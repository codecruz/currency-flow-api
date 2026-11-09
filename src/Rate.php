<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

/**
 * An exchange rate: 1 unit of {@see $base} equals {@see $value} units of {@see $currency}.
 *
 * The value is kept as a decimal string exactly as published, to avoid
 * floating point rounding. Use {@see toFloat()} only when precision is not critical.
 */
final class Rate
{
    /**
     * @param \DateTimeImmutable      $date          Date the rate was actually published for.
     * @param \DateTimeImmutable|null $requestedDate Date that was asked for (null = latest).
     *                                               It differs from $date on weekends and holidays.
     */
    public function __construct(
        public readonly string $base,
        public readonly string $currency,
        public readonly string $value,
        public readonly \DateTimeImmutable $date,
        public readonly ?\DateTimeImmutable $requestedDate,
        public readonly string $source,
    ) {
    }

    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /**
     * Whether the rate comes from an earlier date than the one requested.
     */
    public function isFallback(): bool
    {
        return $this->requestedDate !== null
            && $this->requestedDate->format('Y-m-d') !== $this->date->format('Y-m-d');
    }

    /**
     * @return array{base: string, currency: string, rate: string, date: string, requested_date: string|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'base' => $this->base,
            'currency' => $this->currency,
            'rate' => $this->value,
            'date' => $this->date->format('Y-m-d'),
            'requested_date' => $this->requestedDate?->format('Y-m-d'),
            'source' => $this->source,
        ];
    }
}
