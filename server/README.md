# Currency Flow: self-hosted HTTP API

An HTTP API for the ECB euro exchange reference rates, built on the
[`codecruz/currency-flow`](https://packagist.org/packages/codecruz/currency-flow) library with Slim 4.

Most people don't need to host it: the free [static JSON API](https://codecruz.github.io/currency-flow-api/) serves
the same data. Host this one when you need **arbitrary date ranges**, **your own domain** or **health checks** for
your monitoring.

## Run it locally

```bash
cd server
composer install
composer serve            # http://127.0.0.1:8080
```

```bash
curl http://127.0.0.1:8080/v1/rates/USD?date=2025-03-23
```

```json
{"base":"EUR","currency":"USD","rate":"1.0827","date":"2025-03-21","requested_date":"2025-03-23","source":"ECB"}
```

## Endpoints

The full contract is in [`openapi.yaml`](openapi.yaml) (OpenAPI 3.1), which is also served at `GET /openapi.yaml`.

| Endpoint | Description |
|----------|-------------|
| `GET /` | Index with links to every endpoint |
| `GET /v1/rates?date=YYYY-MM-DD` | All rates for a date (latest publication if omitted) |
| `GET /v1/rates/{currency}?date=YYYY-MM-DD` | One currency. The code is case-insensitive, and `EUR` always returns `1` |
| `GET /v1/rates/{currency}/history?from=YYYY-MM-DD&to=YYYY-MM-DD` | Series between two dates (inclusive), publication days only |
| `GET /v1/currencies` | Currencies in the latest publication |
| `GET /health` | Service health for uptime monitoring |
| `GET /openapi.yaml` | OpenAPI specification |

Rates are decimal strings exactly as published. `date` is the publication used and `requested_date` the date asked
for, so they differ on weekends and holidays.

## Errors

Errors use [RFC 7807](https://www.rfc-editor.org/rfc/rfc7807) (`application/problem+json`):

```json
{
  "type": "https://github.com/codecruz/currency-flow-api#unknown-currency",
  "title": "Not Found",
  "status": 404,
  "detail": "Currency \"XYZ\" is not published by ECB.",
  "instance": "/v1/rates/XYZ"
}
```

| Status | `type` suffix | When |
|--------|---------------|------|
| `400` | `invalid-parameter` | Malformed currency or date, future date, missing or reversed range, repeated parameter |
| `404` | `unknown-currency` | The ECB does not publish that currency |
| `404` | `rate-not-available` | No rate for that date (before 1999-01-04, discontinued currency) |
| `404` | `http-404` | Unknown route |
| `405` | `http-405` | Method other than `GET`, `HEAD` or `OPTIONS` (with an `Allow` header) |
| `502` | `invalid-source-data` | The ECB returned invalid data and there is no cached copy |
| `503` | `source-unavailable` | The ECB is unreachable and there is no cached copy (with `Retry-After: 300`) |
| `500` | `internal-error` | Unexpected error. It is logged and no internal details are returned |

## Caching

- **Data cache:** the ECB feeds are cached on disk (`CACHE_DIR`) for `CACHE_TTL` seconds. If the ECB is down, the
  last copy is served for up to 7 days, so a short outage is invisible to clients.
- **HTTP cache:** every `200` has a strong `ETag`, and sending it back in `If-None-Match` returns `304 Not Modified`.
  - `Cache-Control: public, max-age=300` for the latest publication and recent dates.
  - `Cache-Control: public, max-age=86400` for dates more than 7 days old, which no longer change.
  - `Cache-Control: no-store` for `/health` and errors.
- **CORS:** `Access-Control-Allow-Origin` is `*` by default (`CORS_ORIGIN`), and preflight requests are answered with `204`.

## Health checks

`GET /health` returns `200` when the latest publication is at most 5 days old. It returns `503` when the data is
older, which covers the longest gap between ECB publications at Christmas, or when the ECB is unreachable with
nothing cached:

```json
{"status":"ok","source":"ECB","latest_date":"2026-11-06","age_days":3}
```

## Configuration

Environment variables, all optional:

| Variable | Default | Description |
|----------|---------|-------------|
| `CACHE_DIR` | `server/var/cache` | Directory for the feed cache. It must be writable by the web server |
| `CACHE_TTL` | `3600` | Seconds a cached feed is considered fresh |
| `SOURCE_TIMEOUT` | `10` | Seconds to wait for the ECB |
| `CORS_ORIGIN` | `*` | Value of `Access-Control-Allow-Origin` |
| `APP_DEBUG` | `0` | `1` adds the exception class and message to `500` responses. Never enable it in production |

## Deployment

Requirements: PHP 8.2+ with `xmlreader`, and outbound HTTPS to `www.ecb.europa.eu`.

```bash
git clone https://github.com/codecruz/currency-flow-api.git
cd currency-flow-api/server
composer install --no-dev --optimize-autoloader
mkdir -p var/cache            # or point CACHE_DIR somewhere writable
```

The API expects to be served from the root of a host (for example `rates.example.com`), with
**`server/public` as the document root**. Every request that is not a file must reach `public/index.php`.

**Apache:** [`public/.htaccess`](public/.htaccess) already does the rewrite. It needs `mod_rewrite` and `AllowOverride All`.

**Nginx + PHP-FPM:**

```nginx
server {
    server_name rates.example.com;
    root /var/www/currency-flow-api/server/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

`composer install` links the library from the parent directory, so deploy the whole repository, not only `server/`.

## Development

```bash
composer test       # PHPUnit
composer analyse    # PHPStan, level max
```

The tests run the whole Slim app against fixtures, without network access.
