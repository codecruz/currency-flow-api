<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Tests;

use CodeCruz\CurrencyFlow\RateTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateTableTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function lookups(): iterable
    {
        yield 'before first date' => ['2025-01-01', null];
        yield 'exact first date' => ['2025-01-02', '2025-01-02'];
        yield 'between dates' => ['2025-01-05', '2025-01-03'];
        yield 'exact middle date' => ['2025-01-06', '2025-01-06'];
        yield 'after last date' => ['2025-12-31', '2025-01-07'];
    }

    #[DataProvider('lookups')]
    public function testDateOnOrBefore(string $date, ?string $expected): void
    {
        // Deliberately unsorted: the table must sort itself.
        $table = new RateTable([
            '2025-01-07' => ['USD' => '1.03'],
            '2025-01-02' => ['USD' => '1.04'],
            '2025-01-06' => ['USD' => '1.04'],
            '2025-01-03' => ['USD' => '1.03'],
        ]);

        self::assertSame($expected, $table->dateOnOrBefore($date));
    }
}
