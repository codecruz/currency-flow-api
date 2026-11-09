# Currency Flow

Official euro foreign exchange reference rates from the **European Central Bank**, for PHP.

- Latest, historical (since 1999-01-04) and range lookups.
- Weekends and holidays resolve to the previous publication, and the response tells you so.
- Rates are kept as published decimal strings, no float rounding.
- Optional PSR-16 cache, with stale data served if the ECB is unreachable.
- No HTTP client or framework required.

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
$rate->value;                                  // "1.0821"  (1 EUR = 1.0821 USD)
$rate->date;                                   // DateTimeImmutable 2025-03-21

$rate = $flow->rate('USD', '2025-03-23');      // a Sunday
$rate->date->format('Y-m-d');                  // "2025-03-21"
$rate->isFallback();                           // true

$flow->rates('2025-03-21');                    // ['AUD' => Rate, 'BRL' => Rate, ...]
$flow->history('GBP', '2024-01-01', '2024-12-31'); // list<Rate>, publication days only
$flow->currencies();                           // ['AUD', 'BRL', 'CAD', ...]

$rate->toArray();
// ['base' => 'EUR', 'currency' => 'USD', 'rate' => '1.0821',
//  'date' => '2025-03-21', 'requested_date' => '2025-03-23', 'source' => 'ECB']
```

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

## Development

```bash
composer install
composer test
composer analyse
```

## License

MIT
