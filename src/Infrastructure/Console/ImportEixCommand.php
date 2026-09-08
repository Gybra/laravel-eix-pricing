<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Console;

use Gybra\EixPricing\Application\ImportAlreadyRunning;
use Gybra\EixPricing\Application\ImportOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class ImportEixCommand extends Command
{
    protected $signature = 'eix:import';

    protected $description = 'Import EIX pre-trade sources from the lookback window';

    public function handle(ImportOrchestrator $orchestrator): int
    {
        if (Date::now()->isWeekend()) {
            $this->info('Skipped: markets are closed.');

            return self::SUCCESS;
        }

        try {
            $results = $orchestrator->run();
        } catch (ImportAlreadyRunning $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->info('No EIX sources in the lookback window.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            if ($result->skipped) {
                $this->info("Source {$result->source} was already imported.");
            } else {
                $this->info("Imported {$result->rowsImported} rows from {$result->source}.");
            }
        }

        return self::SUCCESS;
    }
}
