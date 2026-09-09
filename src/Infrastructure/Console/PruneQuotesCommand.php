<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Console;

use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class PruneQuotesCommand extends Command
{
    protected $signature = 'eix:prune-quotes';

    protected $description = 'Delete quotes and imports older than the retention window';

    public function handle(QuoteServiceInterface $quotes, ImportServiceInterface $imports): int
    {
        if (Date::now()->isWeekend()) {
            $this->info('Skipped: markets are closed.');

            return self::SUCCESS;
        }

        $quotesDeleted = $quotes->pruneStale();
        $quoteLabel = $quotesDeleted === 1 ? 'quote' : 'quotes';
        $this->info("Deleted {$quotesDeleted} stale {$quoteLabel}.");

        $importsDeleted = $imports->pruneStale();
        $importLabel = $importsDeleted === 1 ? 'import' : 'imports';
        $this->info("Deleted {$importsDeleted} stale {$importLabel}.");

        return self::SUCCESS;
    }
}
