<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds a strong ETag to successful GET responses and answers 304 when it matches.
 */
final class ETagMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly StreamFactoryInterface $streams)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }

        $etag = '"' . hash('xxh128', (string) $response->getBody()) . '"';
        $response = $response->withHeader('ETag', $etag);

        $ifNoneMatch = array_map('trim', explode(',', $request->getHeaderLine('If-None-Match')));
        if (in_array($etag, $ifNoneMatch, true) || in_array('W/' . $etag, $ifNoneMatch, true)) {
            return $response
                ->withStatus(304)
                ->withoutHeader('Content-Type')
                ->withBody($this->streams->createStream(''));
        }

        return $response;
    }
}
