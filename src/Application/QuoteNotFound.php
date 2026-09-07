<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use RuntimeException;

final class QuoteNotFound extends RuntimeException
{
    public function __construct(string $isin)
    {
        parent::__construct("No quote found for ISIN {$isin}.");
    }
}
