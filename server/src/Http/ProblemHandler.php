<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Server\Http;

use CodeCruz\CurrencyFlow\Exception\InvalidArgument;
use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;
use CodeCruz\CurrencyFlow\Exception\RateNotAvailable;
use CodeCruz\CurrencyFlow\Exception\SourceUnavailable;
use CodeCruz\CurrencyFlow\Exception\UnknownCurrency;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;

/**
 * Renders every error as RFC 7807 "application/problem+json".
 * Unexpected errors are logged and never leak internal details.
 */
final class ProblemHandler
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        [$status, $type, $detail] = match (true) {
            $exception instanceof InvalidArgument => [400, 'invalid-parameter', $exception->getMessage()],
            $exception instanceof UnknownCurrency => [404, 'unknown-currency', $exception->getMessage()],
            $exception instanceof RateNotAvailable => [404, 'rate-not-available', $exception->getMessage()],
            $exception instanceof SourceUnavailable => [503, 'source-unavailable', 'The upstream rate source is temporarily unavailable.'],
            $exception instanceof InvalidSourceData => [502, 'invalid-source-data', 'The upstream rate source returned invalid data.'],
            $exception instanceof HttpException => [$exception->getCode(), 'http-' . $exception->getCode(), $exception->getDescription()],
            default => [500, 'internal-error', 'An unexpected error occurred.'],
        };

        if ($status >= 500 && $logErrors) {
            error_log(sprintf(
                '[currency-flow] %s %s -> %d: %s',
                $request->getMethod(),
                $request->getUri()->getPath(),
                $status,
                $logErrorDetails ? (string) $exception : $exception->getMessage(),
            ));
        }

        $problem = [
            'type' => 'https://github.com/codecruz/currency-flow-api#' . $type,
            'title' => $this->responseFactory->createResponse($status)->getReasonPhrase(),
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getUri()->getPath(),
        ];

        if ($displayErrorDetails && $status === 500) {
            $problem['debug'] = ['exception' => $exception::class, 'message' => $exception->getMessage()];
        }

        $response = JsonResponder::json(
            $this->responseFactory->createResponse(),
            $problem,
            $status,
            contentType: 'application/problem+json',
        );

        if ($status === 503) {
            $response = $response->withHeader('Retry-After', '300');
        }

        if ($exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
        }

        return $response;
    }
}
