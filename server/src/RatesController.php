<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server;

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Exception\InvalidArgument;
use CodeCruz\CurrencyFlow\Rate;
use CodeCruz\CurrencyFlow\Server\Http\JsonResponder;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RatesController
{
    /** Rates older than this are treated as settled and cached for a day. */
    private const SETTLED_AFTER_DAYS = 7;

    private const CACHE_LATEST = 'public, max-age=300';
    private const CACHE_SETTLED = 'public, max-age=86400';

    /** Publications can be up to 5 days apart (e.g. Christmas). */
    private const STALE_AFTER_DAYS = 5;

    public function __construct(
        private readonly CurrencyFlow $flow,
        private readonly ClockInterface $clock,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonResponder::json($response, [
            'name' => 'Currency Flow API',
            'description' => 'Euro foreign exchange reference rates published by the European Central Bank.',
            'documentation' => '/openapi.yaml',
            'endpoints' => [
                'latest_rates' => '/v1/rates',
                'rates_on_date' => '/v1/rates?date=YYYY-MM-DD',
                'currency_rate' => '/v1/rates/{currency}?date=YYYY-MM-DD',
                'currency_history' => '/v1/rates/{currency}/history?from=YYYY-MM-DD&to=YYYY-MM-DD',
                'currencies' => '/v1/currencies',
                'health' => '/health',
            ],
        ], cacheControl: self::CACHE_SETTLED);
    }

    public function rates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $date = $this->query($request, 'date');
        $rates = $this->flow->rates($date);
        $first = reset($rates);

        if ($first === false) {
            throw new \LogicException('Provider returned an empty publication.');
        }

        return JsonResponder::json($response, [
            'base' => $first->base,
            'date' => $first->date->format('Y-m-d'),
            'requested_date' => $date,
            'source' => $first->source,
            'rates' => array_map(static fn (Rate $rate): string => $rate->value, $rates),
        ], cacheControl: $this->cacheFor($date));
    }

    /**
     * @param array{currency: string} $args
     */
    public function rate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $date = $this->query($request, 'date');
        $rate = $this->flow->rate($args['currency'], $date);

        return JsonResponder::json($response, $rate->toArray(), cacheControl: $this->cacheFor($date));
    }

    /**
     * @param array{currency: string} $args
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $from = $this->query($request, 'from') ?? throw new InvalidArgument('Query parameter "from" is required.');
        $to = $this->query($request, 'to') ?? throw new InvalidArgument('Query parameter "to" is required.');

        $history = $this->flow->history($args['currency'], $from, $to);
        $provider = $this->flow->provider();

        return JsonResponder::json($response, [
            'base' => $provider->baseCurrency(),
            'currency' => strtoupper(trim($args['currency'])),
            'from' => $from,
            'to' => $to,
            'source' => $provider->name(),
            'rates' => array_map(
                static fn (Rate $rate): array => ['date' => $rate->date->format('Y-m-d'), 'rate' => $rate->value],
                $history,
            ),
        ], cacheControl: $this->cacheFor($to));
    }

    public function currencies(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provider = $this->flow->provider();

        return JsonResponder::json($response, [
            'base' => $provider->baseCurrency(),
            'source' => $provider->name(),
            'currencies' => $this->flow->currencies(),
        ], cacheControl: self::CACHE_LATEST);
    }

    /**
     * Healthy when the latest publication is recent enough. Source errors surface
     * through the problem handler as 502/503, which is what uptime monitors need.
     */
    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $latest = $this->flow->rate($this->flow->provider()->baseCurrency())->date;
        $age = (int) $latest->diff($this->today())->days;
        $healthy = $age <= self::STALE_AFTER_DAYS;

        return JsonResponder::json($response, [
            'status' => $healthy ? 'ok' : 'stale',
            'source' => $this->flow->provider()->name(),
            'latest_date' => $latest->format('Y-m-d'),
            'age_days' => $age,
        ], $healthy ? 200 : 503);
    }

    public function openApi(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $spec = file_get_contents(dirname(__DIR__) . '/openapi.yaml');
        if ($spec === false) {
            throw new \RuntimeException('openapi.yaml is missing.');
        }

        $response->getBody()->write($spec);

        return $response
            ->withHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->withHeader('Cache-Control', self::CACHE_LATEST);
    }

    private function query(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgument(sprintf('Query parameter "%s" must be a single value.', $name));
        }

        return $value;
    }

    private function cacheFor(?string $date): string
    {
        if ($date === null) {
            return self::CACHE_LATEST;
        }

        $settledBefore = $this->today()->modify(sprintf('-%d days', self::SETTLED_AFTER_DAYS))->format('Y-m-d');

        return $date < $settledBefore ? self::CACHE_SETTLED : self::CACHE_LATEST;
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->setTime(0, 0);
    }
}
