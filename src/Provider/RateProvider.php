<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Provider;

use CodeCruz\CurrencyFlow\Coverage;
use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;
use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\RateTable;

interface RateProvider
{
    /**
     * Short, stable identifier (used in cache keys and in {@see \CodeCruz\CurrencyFlow\Rate::$source}).
     */
    public function name(): string;

    /**
     * ISO 4217 code all rates are quoted against.
     */
    public function baseCurrency(): string;

    /**
     * @throws SourceUnavailable
     * @throws InvalidSourceData
     */
    public function fetch(Coverage $coverage): RateTable;
}
