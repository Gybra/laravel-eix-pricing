<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

final readonly class ImportState
{
    public function __construct(
        public int $id,
        public string $status,
    ) {}
}
