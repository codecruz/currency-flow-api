<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The API is public and read-only, so any origin may read it.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly string $allowOrigin = '*',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $request->getMethod() === 'OPTIONS'
            ? $this->responseFactory->createResponse(204)
                ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'If-None-Match')
                ->withHeader('Access-Control-Max-Age', '86400')
            : $handler->handle($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowOrigin)
            ->withHeader('Access-Control-Expose-Headers', 'ETag, Retry-After');
    }
}
