# Currency Flow: static site and JSON API builder

Builds **[codecruz.github.io/currency-flow-api](https://codecruz.github.io/currency-flow-api/)**: the website and the
static JSON API, published on GitHub Pages by [`pages.yml`](../.github/workflows/pages.yml).

The builder uses the [`codecruz/currency-flow`](https://packagist.org/packages/codecruz/currency-flow) library from
this repository. It downloads the ECB full history **once** and writes every file from that table, so the static
API resolves weekends and holidays exactly like the library and the HTTP API.

## Output

```
index.html                         documentation and converter
404.html                           page for missing routes
v1/meta.json                       source, coverage and generation time
v1/latest.json                     latest publication, every currency
v1/rates/{YYYY-MM-DD}.json         every calendar day from 1999-01-04 to today
v1/currencies.json                 every currency ever published, with its period and status
v1/history/{CUR}.json              full series of a currency
v1/history/{CUR}/{YYYY}.json       series of a currency for one year
```

That is about 11,000 files and 60 MB, built in a few seconds. A daily file is only written if a publication exists
within the previous 7 days, so dates before 1999-01-04 or in a long gap return a `404`.

## Build

```bash
cd pages
composer install
composer build                     # writes _site/
php -S 127.0.0.1:8000 -t _site     # preview at http://127.0.0.1:8000
```

`bin/build` options:

| Option | Default | Description |
|--------|---------|-------------|
| `--out` | `_site` | Output directory. It is wiped first, but only if a previous build created it |
| `--site-url` | `https://codecruz.github.io/currency-flow-api` | Absolute URL used in the examples of `index.html` |
| `--max-age-days` | `5` | Fail if the latest publication is older than this |
| `--min-currencies` | `20` | Fail if fewer currencies are active |

Exit codes: `0` built, `1` the ECB was unreachable or its data was invalid, `2` a sanity check failed. On a non-zero
exit the workflow stops before deploying, so the previous site stays online.

## Schedule

| When (UTC) | Why |
|------------|-----|
| Every day at 00:05 | Publishes the file for the new calendar day |
| Monday to Friday at 15:30 and 16:30 | After the ECB publication (around 16:00 Frankfurt time) |
| Push to `main` touching `src/`, `pages/`, `composer.json` or the workflow | Code changes |
| Manual run | *Actions → Deploy static API → Run workflow* |

GitHub disables scheduled workflows after 60 days without repository activity. Each scheduled run re-enables
the workflow to keep the daily updates going.

## Templates

[`templates/index.html`](templates/index.html) and [`templates/404.html`](templates/404.html) are plain HTML with
`{{PLACEHOLDERS}}` that are filled in at build time (`LATEST_DATE`, `FIRST_DATE`, `TODAY`, `CURRENCY_COUNT`,
`DAILY_FILES`, `SITE_URL`, `GENERATED_AT`). The converter in `index.html` is vanilla JavaScript that reads the
JSON files with relative URLs, so it also works in a local preview.

## Development

```bash
composer test       # PHPUnit, against fixtures and without network access
composer analyse    # PHPStan, level max
```
