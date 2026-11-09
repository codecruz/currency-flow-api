<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

use Psr\Clock\ClockInterface;

/**
 * @internal default clock, used when none is injected
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
