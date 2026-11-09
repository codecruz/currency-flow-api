<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Pages\Tests;

use CodeCruz\CurrencyFlow\Pages\BuildReport;
use CodeCruz\CurrencyFlow\Pages\SiteBuilder;
use CodeCruz\CurrencyFlow\Pages\TableProvider;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbXmlParser;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class SiteBuilderTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/currency-flow-site-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->out)) {
            exec('rm -rf ' . escapeshellarg($this->out));
        }
    }

    private function build(string $now = '2025-03-24 10:00'): BuildReport
    {
        $table = (new EcbXmlParser())->parse((string) file_get_contents(__DIR__ . '/Fixtures/full.xml'));
        $clock = new class (new \DateTimeImmutable($now, new \DateTimeZone('Europe/Berlin'))) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        $builder = new SiteBuilder(new TableProvider($table, 'ECB', 'EUR'), $clock, dirname(__DIR__) . '/templates', dirname(__DIR__) . '/translations', 'https://example.test/api/');

        return $builder->build($this->out);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path): array
    {
        self::assertFileExists($this->out . '/' . $path);
        $data = json_decode((string) file_get_contents($this->out . '/' . $path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testReport(): void
    {
        $report = $this->build();

        self::assertSame('2025-03-21', $report->latestDate);
        self::assertSame(3, $report->ageDays);
        self::assertSame(['GBP', 'USD'], $report->activeCurrencies);
        // 8 + 8 + 12 + 8 + 4 days within the 7-day look-back of each publication.
        self::assertSame(40, $report->dailyFiles);
    }

    public function testLatest(): void
    {
        $this->build();

        self::assertSame([
            'base' => 'EUR',
            'date' => '2025-03-21',
            'requested_date' => null,
            'source' => 'ECB',
            'rates' => ['GBP' => '0.83650', 'USD' => '1.0821'],
        ], $this->json('v1/latest.json'));
    }

    public function testDailyFilesResolveWeekendsAndHolidays(): void
    {
        $this->build();

        $sunday = $this->json('v1/rates/2025-03-23.json');
        self::assertSame('2025-03-21', $sunday['date']);
        self::assertSame('2025-03-23', $sunday['requested_date']);

        $christmas = $this->json('v1/rates/2024-12-25.json');
        self::assertSame('2024-12-23', $christmas['date']);
        self::assertSame(['GBP' => '0.83013', 'USD' => '1.0412'], $christmas['rates']);

        $today = $this->json('v1/rates/2025-03-24.json');
        self::assertSame('2025-03-21', $today['date']);
    }

    public function testNoFilesOutsideTheLookbackWindowOrInTheFuture(): void
    {
        $this->build();

        self::assertFileDoesNotExist($this->out . '/v1/rates/1999-01-03.json');
        self::assertFileDoesNotExist($this->out . '/v1/rates/2022-03-15.json');
        self::assertFileDoesNotExist($this->out . '/v1/rates/2025-03-25.json');
    }

    public function testCurrenciesIncludeDiscontinuedOnes(): void
    {
        $this->build();

        self::assertSame([
            ['code' => 'GBP', 'from' => '2024-12-23', 'to' => '2025-03-21', 'active' => true],
            ['code' => 'RUB', 'from' => '2022-02-28', 'to' => '2022-02-28', 'active' => false],
            ['code' => 'USD', 'from' => '1999-01-04', 'to' => '2025-03-21', 'active' => true],
        ], $this->json('v1/currencies.json')['currencies']);
    }

    public function testHistory(): void
    {
        $this->build();

        $full = $this->json('v1/history/USD.json');
        self::assertSame('1999-01-04', $full['from']);
        self::assertSame('2025-03-21', $full['to']);
        self::assertIsArray($full['rates']);
        self::assertCount(6, $full['rates']);
        self::assertSame(['date' => '1999-01-04', 'rate' => '1.1789'], $full['rates'][0]);

        $year = $this->json('v1/history/USD/2024.json');
        self::assertSame(['2024-01-01', '2024-12-31'], [$year['from'], $year['to']]);
        self::assertSame([
            ['date' => '2024-12-23', 'rate' => '1.0412'],
            ['date' => '2024-12-27', 'rate' => '1.0427'],
        ], $year['rates']);

        self::assertFileDoesNotExist($this->out . '/v1/history/USD/2023.json');
        self::assertFileExists($this->out . '/v1/history/RUB/2022.json');
    }

    public function testMetaAndPages(): void
    {
        $this->build();

        $meta = $this->json('v1/meta.json');
        self::assertSame('1999-01-04', $meta['first_date']);
        self::assertSame('2025-03-21', $meta['latest_date']);
        self::assertSame(2, $meta['currencies']);
        self::assertSame('2025-03-24T09:00:00Z', $meta['generated_at']);

        $index = (string) file_get_contents($this->out . '/index.html');
        self::assertStringNotContainsString('{{', $index);
        self::assertStringContainsString('curl https://example.test/api/v1/rates/', $index);
        self::assertStringContainsString('max="2025-03-24"', $index);
        self::assertFileExists($this->out . '/404.html');
        self::assertFileExists($this->out . '/.nojekyll');
    }

    public function testEveryLanguageHasItsPage(): void
    {
        $this->build();

        foreach (SiteBuilder::LOCALES as $locale) {
            $path = $locale === 'en' ? 'index.html' : $locale . '/index.html';
            $html = (string) file_get_contents($this->out . '/' . $path);
            $url = 'https://example.test/api/' . ($locale === 'en' ? '' : $locale . '/');

            self::assertStringNotContainsString('{{', $html, $path);
            self::assertStringContainsString('<html lang="' . $locale . '">', $html);
            self::assertStringContainsString('<link rel="canonical" href="' . $url . '">', $html);
            self::assertSame(7, substr_count($html, 'rel="alternate" hreflang='), $path);
            self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.test/api/">', $html);
            // Placeholders inside translations are filled in a second pass.
            self::assertStringContainsString('<strong>2025-03-21</strong>', $html);
        }
    }

    public function testPagesInFoldersPointToTheSharedApi(): void
    {
        $this->build();

        $en = (string) file_get_contents($this->out . '/index.html');
        $es = (string) file_get_contents($this->out . '/es/index.html');

        self::assertStringContainsString("load('v1/currencies.json')", $en);
        self::assertStringContainsString("load('../v1/currencies.json')", $es);
        self::assertStringContainsString('<a href="../v1/latest.json">', $es);
        self::assertFileDoesNotExist($this->out . '/es/v1/latest.json');
    }

    public function testLanguageSwitcherAndTranslatedContent(): void
    {
        $this->build();

        $es = (string) file_get_contents($this->out . '/es/index.html');
        self::assertStringContainsString('<a href="./" hreflang="es" lang="es" aria-current="page">Español</a>', $es);
        self::assertStringContainsString('<a href="../" hreflang="en" lang="en">English</a>', $es);
        self::assertStringContainsString('<a href="../de/" hreflang="de" lang="de">Deutsch</a>', $es);
        self::assertStringContainsString('<h1>Tipos de cambio del euro</h1>', $es);
        self::assertStringContainsString('"js_fallback":"El {requested} no hubo publicación; se usa la del {date}."', $es);

        $de = (string) file_get_contents($this->out . '/de/index.html');
        self::assertStringContainsString('<h1>Euro-Wechselkurse</h1>', $de);
    }

    public function testNotFoundPageIsMultilingual(): void
    {
        $this->build();

        $html = (string) file_get_contents($this->out . '/404.html');
        self::assertStringNotContainsString('{{', $html);
        self::assertStringContainsString('Page not found', $html);
        foreach (SiteBuilder::LOCALES as $locale) {
            self::assertStringContainsString('"' . $locale . '":{"title":', $html);
        }
    }

    public function testTranslationsMustHaveTheSameKeys(): void
    {
        $dir = $this->out . '-translations';
        mkdir($dir);
        foreach (SiteBuilder::LOCALES as $locale) {
            copy(dirname(__DIR__) . '/translations/' . $locale . '.json', $dir . '/' . $locale . '.json');
        }
        $de = json_decode((string) file_get_contents($dir . '/de.json'), true);
        self::assertIsArray($de);
        unset($de['h1']);
        file_put_contents($dir . '/de.json', json_encode($de));

        $table = (new EcbXmlParser())->parse((string) file_get_contents(__DIR__ . '/Fixtures/full.xml'));
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2025-03-24 10:00', new \DateTimeZone('Europe/Berlin'));
            }
        };
        $builder = new SiteBuilder(new TableProvider($table, 'ECB', 'EUR'), $clock, dirname(__DIR__) . '/templates', $dir, 'https://example.test/');

        try {
            $builder->build($this->out);
            self::fail('Expected a translation error.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('de.json do not match en.json (missing: h1; extra: none)', $e->getMessage());
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        self::assertDirectoryDoesNotExist($this->out, 'Nothing is written when translations are invalid.');
    }

    public function testRebuildReplacesPreviousOutput(): void
    {
        $this->build();
        touch($this->out . '/v1/rates/stale.json');

        $this->build('2025-03-25 10:00');

        self::assertFileDoesNotExist($this->out . '/v1/rates/stale.json');
        self::assertFileExists($this->out . '/v1/rates/2025-03-25.json');
    }

    public function testRefusesToWipeForeignDirectories(): void
    {
        mkdir($this->out);
        touch($this->out . '/important.txt');

        try {
            $this->build();
            self::fail('Expected the builder to refuse.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }

        self::assertFileExists($this->out . '/important.txt');
    }
}
