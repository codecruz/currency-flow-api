<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Tests\Provider\Ecb;

use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EcbXmlParserTest extends TestCase
{
    public function testParsesFeed(): void
    {
        $table = (new EcbXmlParser())->parse((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/recent.xml'));

        self::assertSame('2025-03-14', $table->firstDate());
        self::assertSame('2025-03-21', $table->lastDate());
        self::assertSame(['USD' => '1.0821', 'JPY' => '161.53', 'GBP' => '0.83650'], $table->on('2025-03-21'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFeeds(): iterable
    {
        $wrap = static fn (string $cubes): string => '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube>' . $cubes . '</Cube></gesmes:Envelope>';

        yield 'empty string' => [''];
        yield 'html error page' => ['<html><body>Service unavailable</body>'];
        yield 'truncated xml' => [substr($wrap("<Cube time='2025-03-21'><Cube currency='USD' rate='1.08'/></Cube>"), 0, -20)];
        yield 'no dates' => [$wrap('')];
        yield 'date without rates' => [$wrap("<Cube time='2025-03-21'></Cube>")];
        yield 'invalid date' => [$wrap("<Cube time='21-03-2025'><Cube currency='USD' rate='1.08'/></Cube>")];
        yield 'invalid currency' => [$wrap("<Cube time='2025-03-21'><Cube currency='usd' rate='1.08'/></Cube>")];
        yield 'non numeric rate' => [$wrap("<Cube time='2025-03-21'><Cube currency='USD' rate='N/A'/></Cube>")];
        yield 'zero rate' => [$wrap("<Cube time='2025-03-21'><Cube currency='USD' rate='0.0000'/></Cube>")];
        yield 'missing rate' => [$wrap("<Cube time='2025-03-21'><Cube currency='USD'/></Cube>")];
        yield 'rate outside a date' => [$wrap("<Cube currency='USD' rate='1.08'/>")];
    }

    #[DataProvider('invalidFeeds')]
    public function testRejectsInvalidFeeds(string $xml): void
    {
        $this->expectException(InvalidSourceData::class);

        (new EcbXmlParser())->parse($xml);
    }
}
