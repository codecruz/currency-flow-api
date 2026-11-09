<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Exception;

/**
 * The upstream data source could not be reached or answered with an error.
 */
final class SourceUnavailable extends \RuntimeException implements CurrencyFlowException
{
}
