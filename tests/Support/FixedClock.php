<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Tests\Support;

use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(public \DateTimeImmutable $now)
    {
    }

    public static function at(string $datetime): self
    {
        return new self(new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Berlin')));
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
