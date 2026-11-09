<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Exception;

/**
 * The currency exists, but there is no rate for the requested date
 * (before the series started, after it was discontinued, or a gap
 * longer than the allowed look-back window).
 */
final class RateNotAvailable extends \RuntimeException implements CurrencyFlowException
{
}
