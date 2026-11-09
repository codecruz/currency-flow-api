<?php

declare(strict_types=1);

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Http\NativeTransport;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;
use CodeCruz\CurrencyFlow\Server\ApiFactory;
use CodeCruz\CurrencyFlow\Server\SystemClock;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static fn (string $name, string $default): string => is_string($value = getenv($name)) && $value !== '' ? $value : $default;

$clock = new SystemClock();
$cache = new Psr16Cache(new FilesystemAdapter('currency-flow', 0, $env('CACHE_DIR', dirname(__DIR__) . '/var/cache')));

$flow = new CurrencyFlow(
    provider: new EcbProvider(new NativeTransport(timeoutSeconds: (float) $env('SOURCE_TIMEOUT', '10'))),
    cache: $cache,
    cacheTtl: (int) $env('CACHE_TTL', '3600'),
    clock: $clock,
);

ApiFactory::create(
    $flow,
    $clock,
    debug: $env('APP_DEBUG', '0') === '1',
    corsOrigin: $env('CORS_ORIGIN', '*'),
)->run();
