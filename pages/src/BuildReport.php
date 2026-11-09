<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Pages;

final class BuildReport
{
    /**
     * @param list<string> $activeCurrencies
     */
    public function __construct(
        public readonly string $latestDate,
        public readonly int $ageDays,
        public readonly array $activeCurrencies,
        public readonly int $dailyFiles,
        public readonly int $files,
    ) {
    }
}
