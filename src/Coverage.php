<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow;

/**
 * How much history a provider is asked to return. Providers that cannot
 * serve a narrower window may return a wider one.
 */
enum Coverage: string
{
    /** Only the most recent publication date. */
    case Latest = 'latest';

    /** Roughly the last 90 calendar days. */
    case Recent = 'recent';

    /** The full published history. */
    case Full = 'full';
}
