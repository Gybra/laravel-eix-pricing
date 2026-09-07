<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Gybra\EixPricing\Domain\Isin;
use Gybra\EixPricing\Domain\Quote;
use Illuminate\Database\DatabaseManager;

final readonly class QuoteLookup
{
    public function __construct(private DatabaseManager $database) {}

    public function find(string $identifier): Quote
    {
        $isin = Isin::from($identifier);
        $row = $this->database
            ->connection(config('eix-pricing.database.connection'))
            ->table('eix_quotes')
            ->where('isin', $isin->value)
            ->first(['isin', 'bid', 'ask', 'price', 'quoted_at']);

        if ($row === null) {
            throw new QuoteNotFound($isin->value);
        }

        return new Quote(
            (string) $row->isin,
            (string) BigDecimal::of((string) $row->bid)->toScale(6),
            (string) BigDecimal::of((string) $row->ask)->toScale(6),
            (string) BigDecimal::of((string) $row->price)->toScale(7),
            new DateTimeImmutable((string) $row->quoted_at, new DateTimeZone('UTC')),
        );
    }
}
