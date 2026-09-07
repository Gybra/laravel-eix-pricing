<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Domain;

final readonly class SourceFile
{
    public function __construct(
        public string $path,
        public int $timestampMilliseconds,
    ) {}
}
