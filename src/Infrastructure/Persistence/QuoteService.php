<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Domain\Quote;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Quote as QuoteModel;
use Illuminate\Support\Facades\Date;

final readonly class QuoteService implements QuoteServiceInterface
{
    public function __construct(private QuoteWriter $writer) {}

    public function findByIsin(string $isin): ?Quote
    {
        $row = QuoteModel::query()
            ->where('isin', $isin)
            ->first(['isin', 'bid', 'ask', 'price', 'quoted_at']);

        if ($row === null) {
            return null;
        }

        return new Quote(
            (string) $row->isin,
            (string) BigDecimal::of((string) $row->bid)->toScale(6),
            (string) BigDecimal::of((string) $row->ask)->toScale(6),
            (string) BigDecimal::of((string) $row->price)->toScale(7),
            new DateTimeImmutable((string) $row->getRawOriginal('quoted_at'), new DateTimeZone('UTC')),
        );
    }

    public function write(iterable $records, int $importId, int $sourceTimestamp): int
    {
        return QuoteModel::query()->getConnection()->transaction(
            fn (): int => $this->writer->write($records, $importId, $sourceTimestamp),
        );
    }

    public function pruneStale(): int
    {
        $cutoff = Date::now()->subDays(max(1, (int) config('eix-pricing.prune.retention_days')));

        return QuoteModel::query()->getConnection()->transaction(
            fn (): int => QuoteModel::query()
                ->where('imported_at', '<=', $cutoff)
                ->delete(),
        );
    }
}
