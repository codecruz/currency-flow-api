# Currency Flow

[![CI](https://github.com/codecruz/currency-flow-api/actions/workflows/ci.yml/badge.svg)](https://github.com/codecruz/currency-flow-api/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/codecruz/currency-flow.svg)](https://packagist.org/packages/codecruz/currency-flow)
[![PHP](https://img.shields.io/packagist/dependency-v/codecruz/currency-flow/php.svg)](https://packagist.org/packages/codecruz/currency-flow)
[![Static API](https://img.shields.io/badge/static%20API-GitHub%20Pages-1f5fbf.svg)](https://codecruz.github.io/currency-flow-api/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official euro foreign exchange reference rates from the **European Central Bank**, for PHP.

- Latest, historical (since 1999-01-04) and range lookups.
- Cross rates and conversions between any two currencies (USD → GBP), not only from the euro.
- Weekends and holidays resolve to the previous publication, and the response tells you so.
- Rates are kept as published decimal strings, no float rounding.
- Optional PSR-16 cache, with stale data served if the ECB is unreachable.
- No HTTP client or framework required.

Also available as a free **static JSON API** on GitHub Pages, no server and no API key:
[codecruz.github.io/currency-flow-api](https://codecruz.github.io/currency-flow-api/).

## Installation

```bash
composer require codecruz/currency-flow
```

Requires PHP 8.2+ with `ext-xmlreader`.

## Usage

```php
use CodeCruz\CurrencyFlow\CurrencyFlow;

$flow = CurrencyFlow::ecb(cache: $psr16Cache); // cache is optional but recommended

$rate = $flow->rate('USD');                    // latest publication
$rate->value;                                  // "1.0827"  (1 EUR = 1.0827 USD)
$rate->date;                                   // DateTimeImmutable 2025-03-21

$rate = $flow->rate('USD', '2025-03-23');      // a Sunday
$rate->date->format('Y-m-d');                  // "2025-03-21"
$rate->isFallback();                           // true

$flow->rates('2025-03-21');                    // ['AUD' => Rate, 'BRL' => Rate, ...]
$flow->history('GBP', '2024-01-01', '2024-12-31'); // list<Rate>, publication days only
$flow->currencies();                           // ['AUD', 'BRL', 'CAD', ...]

$rate->toArray();
// ['base' => 'EUR', 'currency' => 'USD', 'rate' => '1.0827',
//  'date' => '2025-03-21', 'requested_date' => '2025-03-23', 'source' => 'ECB']
```

### Cross rates and conversions

The ECB only publishes rates against the euro. Rates between two other currencies are derived
from both published rates, as the ECB itself suggests, and carry 6 significant digits.

```php
$flow->rate('GBP', '2025-03-21', base: 'USD')->value;   // "0.773668"  (1 USD = 0.773668 GBP)
$flow->rates('2025-03-21', base: 'USD')['EUR']->value;  // "0.923617"

$conversion = $flow->convert(100, 'USD', 'GBP', '2025-03-21');
$conversion->result;                                    // "77.37"
$conversion->rate->value;                               // "0.773668"

$flow->convert('10', 'USD', 'JPY', '2025-03-21', precision: 0)->result; // "1487"
```

`convert()` accepts integers, floats or numeric strings. The result is computed from the published
rates, not from the rounded cross rate, and rounded half away from zero (default: 2 decimals).

Dates can be `YYYY-MM-DD` strings or any `DateTimeInterface`, and are interpreted in the ECB's time zone (Europe/Berlin).

### Errors

Every exception implements `CodeCruz\CurrencyFlow\Exception\CurrencyFlowException`.

| Exception           | When                                                                    |
|---------------------|-------------------------------------------------------------------------|
| `InvalidArgument`   | Malformed currency code or date, future date, reversed range            |
| `UnknownCurrency`   | The ECB does not publish that currency                                  |
| `RateNotAvailable`  | No rate for that date (before the series, discontinued, or gap too long)|
| `SourceUnavailable` | The ECB could not be reached and there is no cached copy                |
| `InvalidSourceData` | The ECB answered with data that failed validation and nothing is cached |

### Configuration

```php
use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;
use CodeCruz\CurrencyFlow\Http\NativeTransport;

$flow = new CurrencyFlow(
    provider: new EcbProvider(new NativeTransport(timeoutSeconds: 5)),
    cache: $psr16Cache,
    cacheTtl: 3600,          // seconds a cached feed is considered fresh
    staleTtl: 7 * 86400,     // seconds a cached feed is kept as fallback when the ECB is down
    maxLookbackDays: 7,      // max distance between requested and published date
    clock: $psr20Clock,      // optional, useful in tests
);
```

Implement `CodeCruz\CurrencyFlow\Http\Transport` to use your own HTTP client, or
`CodeCruz\CurrencyFlow\Provider\RateProvider` to add another data source.

### How data is fetched

The ECB publishes three feeds, used according to the date you ask for:

| Request                     | Feed                    | Size     |
|-----------------------------|-------------------------|----------|
| No date                     | `eurofxref-daily.xml`   | ~2 KB    |
| Within the last ~80 days    | `eurofxref-hist-90d.xml`| ~40 KB   |
| Older                       | `eurofxref-hist.xml`    | several MB |

Each feed is downloaded at most once per instance and once per `cacheTtl` when a cache is provided.
The ECB updates rates around 16:00 CET on TARGET business days.

## Static JSON API (GitHub Pages)

Static JSON files, rebuilt from the ECB feed several times a day by
[`pages.yml`](.github/workflows/pages.yml) using this library. Open CORS, no rate limits,
and a missing date or currency is a `404`.

| Endpoint                                                                                                   | Contents                                                     |
|------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------|
| [`/v1/latest.json`](https://codecruz.github.io/currency-flow-api/v1/latest.json)                           | Latest publication, every currency                           |
| [`/v1/rates/{YYYY-MM-DD}.json`](https://codecruz.github.io/currency-flow-api/v1/rates/2025-03-23.json)     | Any calendar day since 1999-01-04 (weekends resolve to the previous publication) |
| [`/v1/history/{currency}.json`](https://codecruz.github.io/currency-flow-api/v1/history/USD.json)          | Full series of a currency                                    |
| [`/v1/history/{currency}/{year}.json`](https://codecruz.github.io/currency-flow-api/v1/history/USD/2025.json) | Series of a currency for one year                         |
| [`/v1/currencies.json`](https://codecruz.github.io/currency-flow-api/v1/currencies.json)                   | Every currency ever published, with its period and status    |
| [`/v1/meta.json`](https://codecruz.github.io/currency-flow-api/v1/meta.json)                               | Source, coverage and generation time                         |

```bash
curl https://codecruz.github.io/currency-flow-api/v1/rates/2025-03-23.json
```

```json
{"base":"EUR","date":"2025-03-21","requested_date":"2025-03-23","source":"ECB","rates":{"AUD":"1.724","…":"…","USD":"1.0827"}}
```

The builder lives in [`pages/`](pages) (`composer install && composer build`) and is not part of the Composer package.

## HTTP API (self-hosted)

The [`server/`](server) directory contains a ready-to-run HTTP API built on this library
(Slim 4, file cache, RFC 7807 errors, ETags). It is not part of the Composer package.

```bash
cd server
composer install
composer serve            # http://127.0.0.1:8080
```

| Endpoint                                                  | Description                                 |
|-----------------------------------------------------------|---------------------------------------------|
| `GET /v1/rates?date=YYYY-MM-DD`                           | All rates for a date (latest if omitted)    |
| `GET /v1/rates/{currency}?date=YYYY-MM-DD`                | One currency                                |
| `GET /v1/rates/{currency}/history?from=…&to=…`            | Series between two dates                    |
| `GET /v1/currencies`                                      | Currencies in the latest publication        |
| `GET /health`                                             | 200 when data is fresh, 503 otherwise       |
| `GET /openapi.yaml`                                       | OpenAPI 3.1 specification                   |

Configuration through environment variables: `CACHE_DIR`, `CACHE_TTL` (seconds, default 3600),
`SOURCE_TIMEOUT` (seconds, default 10), `CORS_ORIGIN` (default `*`) and `APP_DEBUG` (`1` to show error details).

## Development

```bash
composer install
composer test
composer analyse
```

## License

MIT
