<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Tests;

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Provider\Ecb\EcbProvider;
use CodeCruz\CurrencyFlow\Server\ApiFactory;
use CodeCruz\CurrencyFlow\Server\Tests\Support\FixedClock;
use CodeCruz\CurrencyFlow\Server\Tests\Support\FixtureTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApiTest extends TestCase
{
    private FixtureTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FixtureTransport();
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $uri, string $method = 'GET', array $headers = [], string $now = '2025-03-24 10:00'): ResponseInterface
    {
        $clock = FixedClock::at($now);
        $flow = new CurrencyFlow(new EcbProvider($this->transport), clock: $clock);
        $app = ApiFactory::create($flow, $clock);

        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /**
     * @return array<mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testLatestRate(): void
    {
        $response = $this->request('/v1/rates/usd');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertSame([
            'base' => 'EUR',
            'currency' => 'USD',
            'rate' => '1.0821',
            'date' => '2025-03-21',
            'requested_date' => null,
            'source' => 'ECB',
        ], self::json($response));
    }

    public function testRateOnWeekendFallsBack(): void
    {
        $data = self::json($this->request('/v1/rates/USD?date=2025-03-23'));

        self::assertSame('2025-03-21', $data['date']);
        self::assertSame('2025-03-23', $data['requested_date']);
    }

    public function testOldDatesAreCachedLonger(): void
    {
        $response = $this->request('/v1/rates/USD?date=2024-12-25');

        self::assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));
        self::assertSame('1.0412', self::json($response)['rate']);
    }

    public function testAllRates(): void
    {
        $data = self::json($this->request('/v1/rates?date=2025-03-22'));

        self::assertSame('2025-03-21', $data['date']);
        self::assertSame('2025-03-22', $data['requested_date']);
        self::assertSame(['GBP' => '0.83650', 'JPY' => '161.53', 'USD' => '1.0821'], $data['rates']);
    }

    public function testHistory(): void
    {
        $data = self::json($this->request('/v1/rates/usd/history?from=2025-03-14&to=2025-03-21'));

        self::assertSame('USD', $data['currency']);
        self::assertSame([
            ['date' => '2025-03-14', 'rate' => '1.0880'],
            ['date' => '2025-03-20', 'rate' => '1.0855'],
            ['date' => '2025-03-21', 'rate' => '1.0821'],
        ], $data['rates']);
    }

    public function testCurrencies(): void
    {
        self::assertSame(
            ['base' => 'EUR', 'source' => 'ECB', 'currencies' => ['GBP', 'JPY', 'USD']],
            self::json($this->request('/v1/currencies')),
        );
    }

    public function testHealthy(): void
    {
        $response = $this->request('/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['status' => 'ok', 'source' => 'ECB', 'latest_date' => '2025-03-21', 'age_days' => 3], self::json($response));
    }

    public function testStaleDataIsUnhealthy(): void
    {
        $response = $this->request('/health', now: '2025-03-28 10:00');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('stale', self::json($response)['status']);
    }

    public function testSourceDownIsUnhealthy(): void
    {
        $this->transport->down = true;
        $response = $this->request('/health');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('300', $response->getHeaderLine('Retry-After'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function errors(): iterable
    {
        yield 'invalid currency' => ['/v1/rates/US', 400, 'invalid-parameter'];
        yield 'invalid date' => ['/v1/rates/USD?date=23-03-2025', 400, 'invalid-parameter'];
        yield 'future date' => ['/v1/rates/USD?date=2025-03-25', 400, 'invalid-parameter'];
        yield 'array parameter' => ['/v1/rates/USD?date[]=2025-03-20', 400, 'invalid-parameter'];
        yield 'missing history range' => ['/v1/rates/USD/history?from=2025-03-14', 400, 'invalid-parameter'];
        yield 'reversed history range' => ['/v1/rates/USD/history?from=2025-03-21&to=2025-03-14', 400, 'invalid-parameter'];
        yield 'unknown currency' => ['/v1/rates/XYZ', 404, 'unknown-currency'];
        yield 'before history' => ['/v1/rates/USD?date=1998-12-31', 404, 'rate-not-available'];
        yield 'unknown route' => ['/v2/rates', 404, 'http-404'];
    }

    #[DataProvider('errors')]
    public function testErrorsAreProblemDetails(string $uri, int $status, string $type): void
    {
        $response = $this->request($uri);
        $data = self::json($response);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://github.com/codecruz/currency-flow-api#' . $type, $data['type']);
        self::assertSame($status, $data['status']);
        self::assertIsString($data['detail']);
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->request('/v1/rates', 'POST');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testInvalidSourceDataIsBadGatewayWithoutLeakingDetails(): void
    {
        $this->transport->override = '<html>Maintenance</html>';
        $response = $this->request('/v1/rates/USD');

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('The upstream rate source returned invalid data.', self::json($response)['detail']);
    }

    public function testConditionalRequestReturns304(): void
    {
        $etag = $this->request('/v1/rates/USD')->getHeaderLine('ETag');
        $response = $this->request('/v1/rates/USD', headers: ['If-None-Match' => $etag]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame($etag, $response->getHeaderLine('ETag'));
    }

    public function testCorsPreflight(): void
    {
        $response = $this->request('/v1/rates/USD', 'OPTIONS');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testIndexAndOpenApi(): void
    {
        self::assertSame('/openapi.yaml', self::json($this->request('/'))['documentation']);

        $spec = $this->request('/openapi.yaml');
        self::assertSame(200, $spec->getStatusCode());
        self::assertStringStartsWith('openapi: 3.1.0', (string) $spec->getBody());
    }
}
