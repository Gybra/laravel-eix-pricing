<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application\Contracts;

use Gybra\EixPricing\Domain\Quote;
use Gybra\EixPricing\Domain\QuoteRecord;

interface QuoteServiceInterface
{
    public function findByIsin(string $isin): ?Quote;

    /**
     * @param  iterable<QuoteRecord>  $records
     */
    public function write(iterable $records, int $importId, int $sourceTimestamp): int;

    public function pruneStale(): int;
}
