<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Pages;

use CodeCruz\CurrencyFlow\Coverage;
use CodeCruz\CurrencyFlow\Provider\RateProvider;
use CodeCruz\CurrencyFlow\RateTable;

/**
 * Serves an already downloaded table for every coverage, so the whole site
 * is built from a single download of the full history.
 */
final class TableProvider implements RateProvider
{
    public function __construct(
        private readonly RateTable $table,
        private readonly string $name,
        private readonly string $baseCurrency,
    ) {
    }

    public static function from(RateProvider $provider): self
    {
        return new self($provider->fetch(Coverage::Full), $provider->name(), $provider->baseCurrency());
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function fetch(Coverage $coverage): RateTable
    {
        return $this->table;
    }

    public function table(): RateTable
    {
        return $this->table;
    }
}
