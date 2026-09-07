<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Domain;

use DateTimeImmutable;

final readonly class QuoteRecord
{
    public function __construct(
        public int $sourceRow,
        public string $isin,
        public string $bid,
        public string $ask,
        public string $price,
        public string $status,
        public DateTimeImmutable $quotedAt,
    ) {}
}
