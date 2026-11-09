<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

/**
 * Result of {@see CurrencyFlow::convert()}: {@see $amount} {@see $from} = {@see $result} {@see $to}.
 */
final class Conversion
{
    /**
     * @param string $result Decimal string rounded to the requested precision.
     * @param Rate   $rate   Rate used, with {@see $from} as base (rounded to 6 significant digits when derived).
     */
    public function __construct(
        public readonly string $amount,
        public readonly string $from,
        public readonly string $to,
        public readonly string $result,
        public readonly Rate $rate,
    ) {
    }

    public function toFloat(): float
    {
        return (float) $this->result;
    }

    /**
     * @return array{amount: string, from: string, to: string, result: string, rate: string, date: string, requested_date: string|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'from' => $this->from,
            'to' => $this->to,
            'result' => $this->result,
            'rate' => $this->rate->value,
            'date' => $this->rate->date->format('Y-m-d'),
            'requested_date' => $this->rate->requestedDate?->format('Y-m-d'),
            'source' => $this->rate->source,
        ];
    }
}
