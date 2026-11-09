# Currency Flow: static site and JSON API builder

Builds **[codecruz.github.io/currency-flow-api](https://codecruz.github.io/currency-flow-api/)**: the website and the
static JSON API, published on GitHub Pages by [`pages.yml`](../.github/workflows/pages.yml).

The builder uses the [`codecruz/currency-flow`](https://packagist.org/packages/codecruz/currency-flow) library from
this repository. It downloads the ECB full history **once** and writes every file from that table, so the static
API resolves weekends and holidays exactly like the library and the HTTP API.

## Output

```
index.html                         documentation and converter, in English (x-default)
{es,de,fr,it,pt}/index.html        the same page in each other language
404.html                           page for missing routes, in the visitor's language
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

## Languages

The site is generated in English (at the root, also `x-default`), Spanish, German, French, Italian and Portuguese
(in `/es/`, `/de/`, `/fr/`, `/it/` and `/pt/`), from one template and one file per language in
[`translations/`](translations).

- Every page has a canonical URL, `hreflang` links to all the others and a language switcher.
- When the browser prefers another available language, a dismissible notice links to it. There is no automatic
  redirect, so search engines and shared links always get the page they asked for.
- The converter shows currency names with the browser's `Intl.DisplayNames` and formats numbers for the page's
  language, so no currency names have to be maintained.
- GitHub Pages serves a single `404.html`, which picks the visitor's language in the browser (English otherwise).

### Adding a language

1. Copy [`translations/en.json`](translations/en.json) to `translations/<code>.json` and translate every value.
   `_name` is the language's own name, shown in the switcher, and `_thousands` its thousands separator.
2. Add the code to `SiteBuilder::LOCALES` in [`src/SiteBuilder.php`](src/SiteBuilder.php).
3. Run `composer test`. The build fails if any language is missing a key or has extra ones.

Values may contain HTML (links, `<code>`) and the placeholders `{{LATEST_DATE}}` and `{{FIRST_DATE}}`. Keys
starting with `js_` are used by the converter, and `{date}` and `{requested}` are filled in by the browser.

## Templates

[`templates/index.html`](templates/index.html) and [`templates/404.html`](templates/404.html) are plain HTML with
`{{t:key}}` markers for translations and `{{PLACEHOLDERS}}` for build data (`LATEST_DATE`, `FIRST_DATE`, `TODAY`,
`CURRENCY_COUNT`, `DAILY_FILES`, `SITE_URL`, `GENERATED_AT`). Translations are filled in first, then build data. The
converter is vanilla JavaScript that reads the JSON files with relative URLs, so it also works in a local preview.

## Development

```bash
composer test       # PHPUnit, against fixtures and without network access
composer analyse    # PHPStan, level max
```
