<?php

declare(strict_types=1);

namespace CodeCruz\CurrencyFlow\Provider\Ecb;

use CodeCruz\CurrencyFlow\Exception\InvalidSourceData;
use CodeCruz\CurrencyFlow\RateTable;

/**
 * Streaming parser for the ECB "eurofxref" XML feeds (daily, 90 days and full history).
 */
final class EcbXmlParser
{
    public function parse(string $xml): RateTable
    {
        if (trim($xml) === '') {
            throw new InvalidSourceData('ECB feed is empty.');
        }

        $reader = new \XMLReader();

        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET)) {
                throw new InvalidSourceData('ECB feed is not valid XML.');
            }

            $rates = [];
            $date = null;

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Cube') {
                    continue;
                }

                $time = $reader->getAttribute('time');
                if ($time !== null) {
                    $date = $time;
                    $rates[$date] ??= [];
                    continue;
                }

                $currency = $reader->getAttribute('currency');
                if ($currency === null) {
                    continue;
                }

                if ($date === null) {
                    throw new InvalidSourceData(sprintf('Rate for %s found outside a dated block.', $currency));
                }

                $rates[$date][$currency] = (string) $reader->getAttribute('rate');
            }

            $errors = libxml_get_errors();
            if ($errors !== []) {
                throw new InvalidSourceData('ECB feed is not valid XML: ' . trim($errors[0]->message));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new RateTable($rates);
    }
}
