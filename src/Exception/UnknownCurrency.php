<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Exception;

/**
 * The currency is not published by the provider at all.
 */
final class UnknownCurrency extends \InvalidArgumentException implements CurrencyFlowException
{
    public static function code(string $currency, string $source): self
    {
        return new self(sprintf('Currency "%s" is not published by %s.', $currency, $source));
    }
}
