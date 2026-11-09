<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server;

use CodeCruz\CurrencyFlow\CurrencyFlow;
use CodeCruz\CurrencyFlow\Server\Http\CorsMiddleware;
use CodeCruz\CurrencyFlow\Server\Http\ETagMiddleware;
use CodeCruz\CurrencyFlow\Server\Http\ProblemHandler;
use Psr\Clock\ClockInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ApiFactory
{
    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    public static function create(
        CurrencyFlow $flow,
        ClockInterface $clock,
        bool $debug = false,
        string $corsOrigin = '*',
    ): App {
        $responseFactory = new ResponseFactory();
        $app = AppFactory::create($responseFactory);
        $controller = new RatesController($flow, $clock);

        $app->get('/', [$controller, 'index']);
        $app->get('/openapi.yaml', [$controller, 'openApi']);
        $app->get('/health', [$controller, 'health']);
        $app->get('/v1/currencies', [$controller, 'currencies']);
        $app->get('/v1/rates', [$controller, 'rates']);
        $app->get('/v1/rates/{currency}', [$controller, 'rate']);
        $app->get('/v1/rates/{currency}/history', [$controller, 'history']);

        // Middleware runs last-added-first: CORS wraps everything, including errors.
        $app->add(new ETagMiddleware(new StreamFactory()));
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware($debug, true, $debug);
        $errors->setDefaultErrorHandler(new ProblemHandler($responseFactory));
        $app->add(new CorsMiddleware($responseFactory, $corsOrigin));

        return $app;
    }
}
