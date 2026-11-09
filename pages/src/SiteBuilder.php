<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Pages;

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Exception\RateNotAvailable;
use CodeCruz\CurrencyFlow\Rate;
use Psr\Clock\ClockInterface;

/**
 * Builds the static JSON API served from GitHub Pages.
 *
 *     index.html                         documentation and converter, in English (x-default)
 *     {es,de,fr,it,pt}/index.html        the same page in each other language
 *     404.html                           multilingual not-found page
 *     v1/meta.json                       source, coverage and generation time
 *     v1/latest.json                     latest publication, every currency
 *     v1/rates/{YYYY-MM-DD}.json         every calendar day since 1999-01-04
 *                                        (weekends and holidays resolve to the previous publication)
 *     v1/currencies.json                 every currency ever published, with its coverage
 *     v1/history/{CUR}.json              full series of a currency
 *     v1/history/{CUR}/{YYYY}.json       series of a currency for one year
 */
final class SiteBuilder
{
    private const MARKER = '.currency-flow-site';

    /** Site languages; the first one is the default, served at the root. */
    public const LOCALES = ['en', 'es', 'de', 'fr', 'it', 'pt'];

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private int $files = 0;

    public function __construct(
        private readonly TableProvider $provider,
        private readonly ClockInterface $clock,
        private readonly string $templateDir,
        private readonly string $translationsDir,
        private readonly string $siteUrl,
    ) {
    }

    public function build(string $outDir): BuildReport
    {
        $translations = $this->loadTranslations();
        $this->prepareOutput($outDir);
        $this->files = 0;

        $flow = new CurrencyFlow($this->provider, clock: $this->clock);
        $table = $this->provider->table();
        $base = $this->provider->baseCurrency();
        $source = $this->provider->name();
        $today = $this->today();

        // Latest publication.
        $latest = $flow->rates();
        $latestDate = $this->firstRate($latest)->date->format('Y-m-d');
        $this->writeJson($outDir . '/v1/latest.json', $this->rateSet($latest, null));

        // One file per calendar day, so any date can be looked up by URL.
        $dailyFiles = 0;
        $day = new \DateTimeImmutable($table->firstDate());
        $end = $today->format('Y-m-d');
        while (($date = $day->format('Y-m-d')) <= $end) {
            try {
                $this->writeJson($outDir . '/v1/rates/' . $date . '.json', $this->rateSet($flow->rates($date), $date));
                ++$dailyFiles;
            } catch (RateNotAvailable) {
                // Gap longer than the look-back window: no file, so the URL is a 404.
            }
            $day = $day->modify('+1 day');
        }

        // Currencies and their series.
        /** @var array<string, array<string, string>> $series currency => date => rate */
        $series = [];
        foreach ($table->toArray() as $date => $rates) {
            foreach ($rates as $currency => $value) {
                $series[$currency][$date] = $value;
            }
        }
        ksort($series);

        $currencies = [];
        foreach ($series as $currency => $byDate) {
            $dates = array_keys($byDate);
            $from = (string) $dates[0];
            $to = (string) $dates[count($dates) - 1];
            $currencies[] = ['code' => $currency, 'from' => $from, 'to' => $to, 'active' => $to === $latestDate];

            $this->writeJson($outDir . '/v1/history/' . $currency . '.json', $this->history($currency, $from, $to, $byDate));

            $byYear = [];
            foreach ($byDate as $date => $value) {
                $byYear[substr((string) $date, 0, 4)][(string) $date] = $value;
            }
            foreach ($byYear as $year => $yearSeries) {
                $this->writeJson(
                    $outDir . '/v1/history/' . $currency . '/' . $year . '.json',
                    $this->history($currency, $year . '-01-01', $year . '-12-31', $yearSeries),
                );
            }
        }

        $active = array_values(array_map(
            static fn (array $c): string => $c['code'],
            array_filter($currencies, static fn (array $c): bool => $c['active']),
        ));

        $this->writeJson($outDir . '/v1/currencies.json', [
            'base' => $base,
            'source' => $source,
            'currencies' => $currencies,
        ]);

        $generatedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $this->writeJson($outDir . '/v1/meta.json', [
            'base' => $base,
            'source' => $source,
            'source_url' => 'https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html',
            'attribution' => 'Source: European Central Bank (ECB). Euro foreign exchange reference rates.',
            'first_date' => $table->firstDate(),
            'latest_date' => $latestDate,
            'currencies' => count($active),
            'generated_at' => $generatedAt,
        ]);

        $globals = [
            '{{LATEST_DATE}}' => $latestDate,
            '{{FIRST_DATE}}' => $table->firstDate(),
            '{{TODAY}}' => $end,
            '{{CURRENCY_COUNT}}' => (string) count($active),
            '{{SITE_URL}}' => rtrim($this->siteUrl, '/'),
            '{{GENERATED_AT}}' => $generatedAt,
        ];
        $this->writePages($outDir, $translations, $globals, $dailyFiles);
        $this->write($outDir . '/.nojekyll', '');

        $age = (int) (new \DateTimeImmutable($latestDate, $today->getTimezone()))->diff($today)->days;

        return new BuildReport($latestDate, $age, $active, $dailyFiles, $this->files);
    }

    /**
     * @param array<string, array<string, string>> $translations locale => key => text
     * @param array<string, string>                $globals
     */
    private function writePages(string $outDir, array $translations, array $globals, int $dailyFiles): void
    {
        $siteUrl = rtrim($this->siteUrl, '/');
        $default = self::LOCALES[0];
        $url = static fn (string $locale): string => $siteUrl . '/' . ($locale === $default ? '' : $locale . '/');

        $hreflang = [];
        foreach (self::LOCALES as $locale) {
            $hreflang[] = sprintf('<link rel="alternate" hreflang="%s" href="%s">', $locale, $url($locale));
        }
        $hreflang[] = sprintf('<link rel="alternate" hreflang="x-default" href="%s">', $url($default));

        $suggest = [];
        $notFound = [];
        foreach (self::LOCALES as $locale) {
            $t = $translations[$locale];
            $suggest[$locale] = ['text' => $t['suggest_text'], 'link' => $t['suggest_link'], 'href' => $url($locale)];
            $notFound[$locale] = ['title' => $t['nf_title'], 'text' => $t['nf_text'], 'link' => $t['nf_link'], 'href' => $url($locale)];
        }

        foreach (self::LOCALES as $locale) {
            $t = $translations[$locale];
            $root = $locale === $default ? '' : '../';

            $links = [];
            foreach (self::LOCALES as $other) {
                $links[] = sprintf(
                    '<a href="%s" hreflang="%s" lang="%s"%s>%s</a>',
                    $other === $locale ? './' : $root . ($other === $default ? '' : $other . '/'),
                    $other,
                    $other,
                    $other === $locale ? ' aria-current="page"' : '',
                    htmlspecialchars($translations[$other]['_name']),
                );
            }

            $jsStrings = array_filter($t, static fn (string $key): bool => str_starts_with($key, 'js_'), ARRAY_FILTER_USE_KEY);

            $this->writeTemplate(
                $outDir . '/' . ($locale === $default ? '' : $locale . '/') . 'index.html',
                'index.html',
                $this->translationPairs($t),
                [
                    '{{LANG}}' => $locale,
                    '{{ROOT}}' => $root,
                    '{{CANONICAL}}' => $url($locale),
                    '{{HREFLANG}}' => implode("\n", $hreflang),
                    '{{LANGUAGE_LINKS}}' => implode(' ', $links),
                    '{{DAILY_FILES}}' => number_format($dailyFiles, 0, '', $t['_thousands']),
                    '{{JS_STRINGS}}' => json_encode($jsStrings, self::JSON_FLAGS | JSON_HEX_TAG),
                    '{{SUGGEST_JSON}}' => json_encode($suggest, self::JSON_FLAGS | JSON_HEX_TAG),
                ] + $globals,
            );
        }

        $this->writeTemplate(
            $outDir . '/404.html',
            '404.html',
            $this->translationPairs($translations[$default]),
            ['{{NF_JSON}}' => json_encode($notFound, self::JSON_FLAGS | JSON_HEX_TAG)] + $globals,
        );
    }

    /**
     * @param array<string, string> $translation
     *
     * @return array<string, string>
     */
    private function translationPairs(array $translation): array
    {
        $pairs = [];
        foreach ($translation as $key => $text) {
            $pairs['{{t:' . $key . '}}'] = $text;
        }

        return $pairs;
    }

    /**
     * Loads every locale and checks they all have exactly the keys of the default one.
     *
     * @return array<string, array<string, string>>
     */
    private function loadTranslations(): array
    {
        $translations = [];
        foreach (self::LOCALES as $locale) {
            $file = $this->translationsDir . '/' . $locale . '.json';
            $content = is_file($file) ? file_get_contents($file) : false;
            if ($content === false) {
                throw new \RuntimeException(sprintf('Missing translation file "%s".', $file));
            }

            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException(sprintf('Translation file "%s" must be a JSON object.', $file));
            }

            $strings = [];
            foreach ($data as $key => $text) {
                if (!is_string($text) || trim($text) === '') {
                    throw new \RuntimeException(sprintf('Translation "%s" in %s.json must be a non-empty string.', $key, $locale));
                }
                $strings[(string) $key] = $text;
            }
            $translations[$locale] = $strings;
        }

        $reference = array_keys($translations[self::LOCALES[0]]);
        foreach ($translations as $locale => $strings) {
            $missing = array_diff($reference, array_keys($strings));
            $extra = array_diff(array_keys($strings), $reference);
            if ($missing !== [] || $extra !== []) {
                throw new \RuntimeException(sprintf(
                    'Translation keys of %s.json do not match %s.json (missing: %s; extra: %s).',
                    $locale,
                    self::LOCALES[0],
                    implode(', ', $missing) ?: 'none',
                    implode(', ', $extra) ?: 'none',
                ));
            }
        }

        return $translations;
    }

    /**
     * @param array<string, Rate> $rates
     *
     * @return array{base: string, date: string, requested_date: string|null, source: string, rates: array<string, string>}
     */
    private function rateSet(array $rates, ?string $requested): array
    {
        $first = $this->firstRate($rates);

        return [
            'base' => $first->base,
            'date' => $first->date->format('Y-m-d'),
            'requested_date' => $requested,
            'source' => $first->source,
            'rates' => array_map(static fn (Rate $rate): string => $rate->value, $rates),
        ];
    }

    /**
     * @param array<string, string> $byDate
     *
     * @return array<string, mixed>
     */
    private function history(string $currency, string $from, string $to, array $byDate): array
    {
        $rates = [];
        foreach ($byDate as $date => $value) {
            $rates[] = ['date' => (string) $date, 'rate' => $value];
        }

        return [
            'base' => $this->provider->baseCurrency(),
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'source' => $this->provider->name(),
            'rates' => $rates,
        ];
    }

    /**
     * @param array<string, Rate> $rates
     */
    private function firstRate(array $rates): Rate
    {
        $first = reset($rates);
        if ($first === false) {
            throw new \RuntimeException('The provider returned an empty publication.');
        }

        return $first;
    }

    /**
     * Refuses to wipe a directory that was not created by this builder.
     */
    private function prepareOutput(string $outDir): void
    {
        if (is_dir($outDir)) {
            $entries = array_diff((array) scandir($outDir), ['.', '..']);
            if ($entries !== [] && !is_file($outDir . '/' . self::MARKER)) {
                throw new \RuntimeException(sprintf('Refusing to overwrite "%s": it is not an output directory of this builder.', $outDir));
            }
            $this->remove($outDir);
        }

        if (!mkdir($outDir, 0o777, true) && !is_dir($outDir)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $outDir));
        }
        touch($outDir . '/' . self::MARKER);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }

    /**
     * Fills a template in successive passes, so placeholders inside translations
     * (filled in the first pass) are replaced by the next ones.
     *
     * @param array<string, string> ...$passes
     */
    private function writeTemplate(string $path, string $template, array ...$passes): void
    {
        $content = file_get_contents($this->templateDir . '/' . $template);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Template "%s" not found.', $template));
        }

        foreach ($passes as $replacements) {
            $content = strtr($content, $replacements);
        }

        $this->write($path, $content);
    }

    private function writeJson(string $path, mixed $data): void
    {
        $this->write($path, json_encode($data, self::JSON_FLAGS));
    }

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', $dir));
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
        }
        ++$this->files;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->setTime(0, 0);
    }
}
