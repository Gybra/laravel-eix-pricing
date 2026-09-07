<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Domain;

final readonly class ImportResult
{
    public function __construct(
        public string $source,
        public int $rowsImported,
        public bool $skipped,
    ) {}
}
