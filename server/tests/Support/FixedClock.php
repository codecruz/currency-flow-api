<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Tests\Support;

use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public static function at(string $datetime): self
    {
        return new self(new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Berlin')));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
