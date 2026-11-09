# 💱 Currency Flow

[![CI](https://github.com/codecruz/currency-flow-api/actions/workflows/ci.yml/badge.svg)](https://github.com/codecruz/currency-flow-api/actions/workflows/ci.yml)
[![Static API](https://github.com/codecruz/currency-flow-api/actions/workflows/pages.yml/badge.svg)](https://codecruz.github.io/currency-flow-api/)
[![Packagist](https://img.shields.io/packagist/v/codecruz/currency-flow.svg)](https://packagist.org/packages/codecruz/currency-flow)
[![PHP](https://img.shields.io/packagist/dependency-v/codecruz/currency-flow/php.svg)](https://packagist.org/packages/codecruz/currency-flow)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The **euro foreign exchange reference rates** published by the **European Central Bank**, from
1999 to today. It is updated automatically every business day. You can use it in three ways:

| | |
|---|---|
| 🌐 **Website and static JSON API** | [`codecruz.github.io/currency-flow-api`](https://codecruz.github.io/currency-flow-api/): no server, no API key, open CORS |
| 🐘 **PHP library** | [`codecruz/currency-flow`](https://packagist.org/packages/codecruz/currency-flow): lookups, history, cross rates and conversions, via Composer |
| 🖥️ **Self-hosted HTTP API** | [`server/`](server): the same data behind your own endpoint (Slim 4, OpenAPI 3.1) |

**29 active currencies · 12 discontinued · every day since 1999-01-04**

- Weekends and holidays resolve to the previous publication, and every answer says which date was used.
- Rates are kept as the decimal strings the ECB publishes, with no float rounding.
- Cross rates and conversions between any two currencies (USD → GBP), not only from the euro.

## Contents

- [Where the data comes from](#where-the-data-comes-from)
- [Website and static JSON API](#website-and-static-json-api)
- [PHP library](#php-library)
- [Self-hosted HTTP API](#self-hosted-http-api)
- [Development](#development)

## Where the data comes from

The [euro foreign exchange reference rates](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)
are published by the **European Central Bank** around 16:00 Frankfurt time on every TARGET business day.
They state how many units of each currency equal **1 EUR**.

- **Coverage:** from 1999-01-04 to today. There are no publications on weekends or TARGET holidays (1 January, Good Friday, Easter Monday, 1 May, 25 and 26 December).
- **Discontinued currencies:** currencies that joined the euro (CYP, EEK, HRK, LTL, LVL, MTL, SIT, SKK, BGN…) or stopped being published (RUB since March 2022) keep their history up to their last date.
- **Cross rates:** the ECB only publishes rates against the euro. Any other pair is derived from both rates, as the ECB itself suggests, and carries 6 significant digits.
- **Terms:** the ECB publishes these rates **for information purposes only**. Please cite *Source: European Central Bank*.

## Website and static JSON API

**[codecruz.github.io/currency-flow-api](https://codecruz.github.io/currency-flow-api/)** has the documentation and a
converter between any two currencies, and serves every endpoint as a static JSON file on GitHub Pages. It has open
CORS and no rate limits, and a missing date or currency returns a `404`.

The website is available in [English](https://codecruz.github.io/currency-flow-api/),
[Español](https://codecruz.github.io/currency-flow-api/es/), [Deutsch](https://codecruz.github.io/currency-flow-api/de/),
[Français](https://codecruz.github.io/currency-flow-api/fr/), [Italiano](https://codecruz.github.io/currency-flow-api/it/)
and [Português](https://codecruz.github.io/currency-flow-api/pt/). Currency names and numbers follow the reader's
language, and visitors are offered (never forced) their browser's language. The JSON API is language-neutral:
ISO 4217 codes, ISO 8601 dates and decimal strings.

| Endpoint | Contents |
|----------|----------|
| [`/v1/latest.json`](https://codecruz.github.io/currency-flow-api/v1/latest.json) | Latest publication, every currency |
| [`/v1/rates/{YYYY-MM-DD}.json`](https://codecruz.github.io/currency-flow-api/v1/rates/2025-03-23.json) | Any calendar day since 1999-01-04. Weekends and holidays resolve to the previous publication |
| [`/v1/history/{currency}.json`](https://codecruz.github.io/currency-flow-api/v1/history/USD.json) | Full series of a currency |
| [`/v1/history/{currency}/{year}.json`](https://codecruz.github.io/currency-flow-api/v1/history/USD/2025.json) | Series of a currency for one year |
| [`/v1/currencies.json`](https://codecruz.github.io/currency-flow-api/v1/currencies.json) | Every currency ever published, with its period and status |
| [`/v1/meta.json`](https://codecruz.github.io/currency-flow-api/v1/meta.json) | Source, coverage and generation time |

### Responses

`/v1/rates/{date}.json` and `/v1/latest.json`. Here `date` is the publication used and `requested_date` the date asked
for (`null` in `latest.json`). When they differ, the requested day was a weekend or holiday:

```bash
curl https://codecruz.github.io/currency-flow-api/v1/rates/2025-03-23.json
```

```json
{
  "base": "EUR",
  "date": "2025-03-21",
  "requested_date": "2025-03-23",
  "source": "ECB",
  "rates": { "AUD": "1.724", "BRL": "6.1911", "…": "…", "USD": "1.0827" }
}
```

`/v1/history/{currency}/{year}.json` and `/v1/history/{currency}.json` include publication days only:

```json
{
  "base": "EUR",
  "currency": "USD",
  "from": "2025-01-01",
  "to": "2025-12-31",
  "source": "ECB",
  "rates": [{ "date": "2025-01-02", "rate": "1.0321" }, { "date": "2025-01-03", "rate": "1.0299" }, "…"]
}
```

`/v1/currencies.json` uses `active: false` for currencies that are no longer published:

```json
{
  "base": "EUR",
  "source": "ECB",
  "currencies": [
    { "code": "AUD", "from": "1999-01-04", "to": "2026-11-06", "active": true },
    { "code": "RUB", "from": "2005-04-01", "to": "2022-03-01", "active": false }
  ]
}
```

`/v1/meta.json`:

```json
{
  "base": "EUR",
  "source": "ECB",
  "source_url": "https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html",
  "attribution": "Source: European Central Bank (ECB). Euro foreign exchange reference rates.",
  "first_date": "1999-01-04",
  "latest_date": "2026-11-06",
  "currencies": 29,
  "generated_at": "2026-11-09T09:49:20Z"
}
```

### When it updates

The site is rebuilt from the ECB feed by [`pages.yml`](.github/workflows/pages.yml):

| When (UTC) | Why |
|------------|-----|
| Every day at 00:05 | Publishes the file for the new calendar day (it resolves to the previous publication) |
| Monday to Friday at 15:30 | After the ECB publication |
| Monday to Friday at 16:30 | Second attempt, in case the publication was late |
| On every push to `main` that changes `src/` or `pages/` | Code changes |

If the ECB is unreachable, the data fails validation, the latest publication is more than 5 days old or fewer than
20 currencies are active, the build fails and **nothing is published**. The previous site stays online and GitHub
emails the repository owner. Details are in [`pages/README.md`](pages/README.md).

## PHP library

```bash
composer require codecruz/currency-flow
```

Requires PHP 8.2+ with `ext-xmlreader`. It only depends on the `psr/clock` and `psr/simple-cache` interfaces.

### Usage

```php
use CodeCruz\CurrencyFlow\CurrencyFlow;

$flow = CurrencyFlow::ecb(cache: $psr16Cache);     // cache is optional but recommended

$rate = $flow->rate('USD', '2025-03-21');
$rate->value;                                      // "1.0827"  (1 EUR = 1.0827 USD)
$rate->date->format('Y-m-d');                      // "2025-03-21"

$rate = $flow->rate('USD', '2025-03-23');          // a Sunday
$rate->date->format('Y-m-d');                      // "2025-03-21"
$rate->isFallback();                               // true

$flow->rate('USD');                                // latest publication
$flow->rates('2025-03-21');                        // ['AUD' => Rate, 'BRL' => Rate, ...]
$flow->history('GBP', '2024-01-01', '2024-12-31'); // list<Rate>, publication days only
$flow->currencies();                               // ['AUD', 'BRL', 'CAD', ...]

$rate->toArray();
// ['base' => 'EUR', 'currency' => 'USD', 'rate' => '1.0827',
//  'date' => '2025-03-21', 'requested_date' => '2025-03-23', 'source' => 'ECB']
```

Dates can be `YYYY-MM-DD` strings or any `DateTimeInterface`. They are interpreted in the ECB's time zone (Europe/Berlin),
and future dates are rejected.

### Cross rates and conversions

```php
$flow->rate('GBP', '2025-03-21', base: 'USD')->value;   // "0.773668"  (1 USD = 0.773668 GBP)
$flow->rates('2025-03-21', base: 'USD')['EUR']->value;  // "0.923617"

$conversion = $flow->convert(100, 'USD', 'GBP', '2025-03-21');
$conversion->result;                                    // "77.37"
$conversion->rate->value;                               // "0.773668"
$conversion->toArray();
// ['amount' => '100', 'from' => 'USD', 'to' => 'GBP', 'result' => '77.37', 'rate' => '0.773668',
//  'date' => '2025-03-21', 'requested_date' => '2025-03-21', 'source' => 'ECB']

$flow->convert('10', 'USD', 'JPY', '2025-03-21', precision: 0)->result; // "1487"
```

`convert()` accepts integers, floats or numeric strings. The result is computed from the published rates, not from
the rounded cross rate. It is rounded half away from zero to `precision` decimals (default 2, maximum 10).

### API reference

| Method | Returns |
|--------|---------|
| `rate(string $currency, $date = null, ?string $base = null)` | `Rate` |
| `rates($date = null, ?string $base = null)` | `array<string, Rate>`, keyed by currency (the base itself is excluded) |
| `convert($amount, string $from, string $to, $date = null, int $precision = 2)` | `Conversion` |
| `history(string $currency, $from, $to)` | `list<Rate>`, oldest first, publication days only |
| `currencies()` | `list<string>`, currencies in the latest publication |

`Rate` has the public read-only properties `base`, `currency`, `value` (decimal string), `date` (publication used),
`requestedDate` and `source`, plus `isFallback()`, `toFloat()` and `toArray()`.
`Conversion` has `amount`, `from`, `to`, `result` (decimal string) and `rate`, plus `toFloat()` and `toArray()`.

### Errors

Every exception implements `CodeCruz\CurrencyFlow\Exception\CurrencyFlowException`.

| Exception | When |
|-----------|------|
| `InvalidArgument` | Malformed currency code, date or amount, future date, reversed range |
| `UnknownCurrency` | The ECB does not publish that currency |
| `RateNotAvailable` | No rate for that date (before the series, discontinued, or a gap longer than the look-back limit) |
| `SourceUnavailable` | The ECB could not be reached and there is no cached copy |
| `InvalidSourceData` | The ECB answered with data that failed validation and there is no cached copy |

### Configuration

```php
use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Http\NativeTransport;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;

$flow = new CurrencyFlow(
    provider: new EcbProvider(new NativeTransport(timeoutSeconds: 5)),
    cache: $psr16Cache,      // any PSR-16 cache (Symfony, Laravel, ...)
    cacheTtl: 3600,          // seconds a cached feed is considered fresh
    staleTtl: 7 * 86400,     // seconds a cached feed is kept as fallback when the ECB is down
    maxLookbackDays: 7,      // max distance between the requested and the published date
    clock: $psr20Clock,      // optional, useful in tests
);
```

Implement `CodeCruz\CurrencyFlow\Http\Transport` to use your own HTTP client, or
`CodeCruz\CurrencyFlow\Provider\RateProvider` to add another data source.

### How data is fetched

The ECB publishes three feeds. The library picks one according to the date you ask for:

| Request | Feed | Size |
|---------|------|------|
| No date | `eurofxref-daily.xml` | ~2 KB |
| Within the last ~80 days | `eurofxref-hist-90d.xml` | ~40 KB |
| Older | `eurofxref-hist.xml` | several MB |

Each feed is downloaded at most once per instance, and at most once per `cacheTtl` when a cache is provided.
Loading the full history takes about 40 MB of memory.

## Self-hosted HTTP API

[`server/`](server) is a ready-to-run HTTP API built on the library. It uses Slim 4, a file cache, RFC 7807 errors
and ETags, and has an [OpenAPI 3.1 specification](server/openapi.yaml). Use it when you need arbitrary date ranges
or want to serve the data from your own domain.

| Endpoint | Description |
|----------|-------------|
| `GET /v1/rates?date=YYYY-MM-DD` | All rates for a date (latest if omitted) |
| `GET /v1/rates/{currency}?date=YYYY-MM-DD` | One currency |
| `GET /v1/rates/{currency}/history?from=…&to=…` | Series between any two dates |
| `GET /v1/currencies` | Currencies in the latest publication |
| `GET /health` | `200` when data is fresh, `503` otherwise |
| `GET /openapi.yaml` | OpenAPI 3.1 specification |

Installation, configuration, errors, caching and deployment are documented in [`server/README.md`](server/README.md).

## Development

```
src/                  PHP library (the Composer package)
tests/                Library tests
pages/                Static site and JSON API builder (GitHub Pages)
server/               Self-hosted HTTP API (Slim 4)
.github/workflows/    CI and Pages deployment
```

`pages/` and `server/` are separate Composer projects. They use the library from this repository through a
path repository, and they are not part of the Composer package.

```bash
composer install && composer test && composer analyse                       # library
(cd pages && composer install && composer test && composer build)           # static site into pages/_site
(cd server && composer install && composer test && composer serve)          # HTTP API on 127.0.0.1:8080
```

### Automation

| Workflow | What it does |
|----------|--------------|
| [`ci.yml`](.github/workflows/ci.yml) | Library tests on PHP 8.2–8.4 plus lowest dependencies, PHPStan, and the tests of `pages/` and `server/` |
| [`pages.yml`](.github/workflows/pages.yml) | Rebuilds and deploys the static API on the schedule above and on changes |

Packagist is updated by its GitHub webhook whenever a tag is pushed.

### Releasing

The library follows [Semantic Versioning](https://semver.org/). Changes are recorded in [`CHANGELOG.md`](CHANGELOG.md).

| Change | Version bump |
|--------|--------------|
| Bug fixes, documentation, package metadata | **patch** |
| New methods, parameters or providers | **minor** |
| Removed or renamed methods, changed return shapes | **major** |

1. Move the entries under `[Unreleased]` in `CHANGELOG.md` to a new version and update the comparison links.
2. Merge to `main` and wait for CI to pass.
3. Tag and push: `git tag -a vX.Y.Z -m vX.Y.Z && git push origin vX.Y.Z`. Packagist picks it up within a minute.

Changes to `pages/` or `server/` only need a merge to `main`. They are not released to Packagist.

## License

Code under the [MIT License](LICENSE). Exchange rates: © European Central Bank, published for information purposes.
