<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Domain\Isin;
use Gybra\EixPricing\Domain\Quote;

final readonly class QuoteLookup
{
    public function __construct(private QuoteServiceInterface $quotes) {}

    public function find(string $identifier): Quote
    {
        $isin = Isin::from($identifier);
        $quote = $this->quotes->findByIsin($isin->value);

        if ($quote === null) {
            throw new QuoteNotFound($isin->value);
        }

        return $quote;
    }
}
