<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Console;

use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class PruneQuotesCommand extends Command
{
    protected $signature = 'eix:prune-quotes';

    protected $description = 'Delete quotes whose imported_at is older than the retention window';

    public function handle(QuoteServiceInterface $quotes): int
    {
        if (Date::now()->isWeekend()) {
            $this->info('Skipped: markets are closed.');

            return self::SUCCESS;
        }

        $deleted = $quotes->pruneStale();
        $label = $deleted === 1 ? 'quote' : 'quotes';

        $this->info("Deleted {$deleted} stale {$label}.");

        return self::SUCCESS;
    }
}
