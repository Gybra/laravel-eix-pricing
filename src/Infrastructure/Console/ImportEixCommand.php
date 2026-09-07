<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Console;

use Gybra\EixPricing\Application\ImportOrchestrator;
use Illuminate\Console\Command;

final class ImportEixCommand extends Command
{
    protected $signature = 'eix:import';

    protected $description = 'Import the newest EIX pre-trade source';

    public function handle(ImportOrchestrator $orchestrator): int
    {
        $result = $orchestrator->run();

        if ($result->skipped) {
            $this->info("Source {$result->source} was already imported.");
        } else {
            $this->info("Imported {$result->rowsImported} rows from {$result->source}.");
        }

        return self::SUCCESS;
    }
}
