<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Exception;

/**
 * The upstream data source answered, but the payload failed validation.
 */
final class InvalidSourceData extends \UnexpectedValueException implements CurrencyFlowException
{
}
