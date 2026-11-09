<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Http;

use Psr\Http\Message\ResponseInterface;

final class JsonResponder
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @param array<mixed> $data
     */
    public static function json(
        ResponseInterface $response,
        array $data,
        int $status = 200,
        string $cacheControl = 'no-store',
        string $contentType = 'application/json',
    ): ResponseInterface {
        $response->getBody()->write(json_encode($data, self::FLAGS));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', $contentType . '; charset=utf-8')
            ->withHeader('Cache-Control', $cacheControl);
    }
}
