<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Domain;

use DateTimeImmutable;

final readonly class Quote
{
    public function __construct(
        public string $isin,
        public string $bid,
        public string $ask,
        public string $price,
        public DateTimeImmutable $quotedAt,
    ) {}
}
