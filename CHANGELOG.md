# Changelog

All notable changes to `codecruz/currency-flow` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-11-09

### Added

- Cross rates: `rate()` and `rates()` accept an optional `base` currency (for example
  `rate('GBP', base: 'USD')`). Derived rates carry 6 significant digits.
- `convert($amount, $from, $to, $date = null, $precision = 2)` converts between any two
  currencies and returns a `Conversion` with the result, the rate used and its publication date.

## [0.1.0] - 2026-11-09

### Added

- Euro foreign exchange reference rates from the European Central Bank (daily, 90-day and full
  history XML feeds).
- `rate()`, `rates()`, `history()` and `currencies()`, with previous-business-day resolution and a
  configurable look-back limit.
- Strict validation of the downloaded data.
- Optional PSR-16 cache that serves stale data when the ECB is unreachable.
- Typed exceptions under `CurrencyFlowException`.

[Unreleased]: https://github.com/codecruz/currency-flow-api/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/codecruz/currency-flow-api/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/codecruz/currency-flow-api/releases/tag/v0.1.0
