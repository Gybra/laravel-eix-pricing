<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Domain\ImportResult;
use Gybra\EixPricing\Domain\Isin;
use Gybra\EixPricing\Domain\SourceFile;
use Gybra\EixPricing\Infrastructure\Console\ProgressReporter;
use Gybra\EixPricing\Infrastructure\Eix\EixCsvParser;
use Gybra\EixPricing\Infrastructure\Eix\EixDiscoveryClient;
use Gybra\EixPricing\Infrastructure\Eix\EixSourceDownloader;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class ImportOrchestrator
{
    public function __construct(
        private CacheManager $cache,
        private ImportServiceInterface $imports,
        private QuoteServiceInterface $quotes,
        private EixDiscoveryClient $discovery,
        private EixSourceDownloader $downloader,
        private EixCsvParser $parser,
        private ProgressReporter $progress,
    ) {}

    /**
     * @return list<ImportResult>
     */
    public function run(?string $isins = null): array
    {
        if (Date::now()->isWeekend()) {
            return [];
        }

        $repository = $this->cache->store(config('eix-pricing.import.lock_store'));

        if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support locks.');
        }

        $lock = $repository->getStore()->lock(
            (string) config('eix-pricing.import.lock_name'),
            (int) config('eix-pricing.import.lock_seconds'),
        );

        if (! $lock->get()) {
            throw new ImportAlreadyRunning;
        }

        try {
            return $this->importSources($isins);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<ImportResult>
     */
    private function importSources(?string $isins): array
    {
        $allowedIsins = $this->allowedIsins($isins);
        $lookback = max(0, (int) config('eix-pricing.import.lookback_minutes'));
        $this->progress->say("Discovering EIX sources from the last {$lookback} minutes...");

        if ($allowedIsins !== null) {
            $count = count($allowedIsins);
            $this->progress->say($count === 1 ? 'Filtering to 1 ISIN.' : "Filtering to {$count} ISINs.");
        }

        $sources = $this->discovery->recent();

        if ($sources === []) {
            return [];
        }

        $count = count($sources);
        $this->progress->say($count === 1 ? 'Found 1 source.' : "Found {$count} sources.");

        $results = [];

        foreach ($sources as $index => $source) {
            $this->progress->say('['.($index + 1)."/{$count}] {$source->path}");
            $results[] = $this->import($source, $allowedIsins, $isins === null);
        }

        return $results;
    }

    /**
     * @param  list<string>|null  $allowedIsins
     */
    private function import(SourceFile $source, ?array $allowedIsins, bool $complete): ImportResult
    {
        $existing = $this->imports->findBySource($source->path);

        if ($existing?->status === 'completed') {
            return new ImportResult($source->path, 0, true);
        }

        $importId = $this->imports->start($source->path, $existing?->id);
        $startedAt = hrtime(true);

        try {
            return $this->downloader->withSource(
                $source,
                fn (string $path): ImportResult => $this->persist(
                    $source,
                    $importId,
                    $path,
                    $startedAt,
                    $allowedIsins,
                    $complete,
                ),
            );
        } catch (Throwable $exception) {
            $this->progress->say('Import failed: '.$exception->getMessage());

            try {
                $this->failImport($importId, $exception);
            } catch (Throwable $metadataException) {
                report($metadataException);
            }

            throw $exception;
        }
    }

    /**
     * @param  list<string>|null  $allowedIsins
     */
    private function persist(
        SourceFile $source,
        int $importId,
        string $path,
        int $startedAt,
        ?array $allowedIsins,
        bool $complete,
    ): ImportResult {
        $parsingStartedAt = hrtime(true);
        $result = $this->imports->transaction(function () use ($source, $importId, $path, $allowedIsins, $complete): ImportResult {
            $rowsImported = $this->quotes->write(
                $this->parser->records($path, $allowedIsins),
                $importId,
                $source->timestampMilliseconds,
            );

            if ($complete) {
                $this->imports->complete($importId, $rowsImported);
            }

            return new ImportResult($source->path, $rowsImported, false);
        });

        Log::info('EIX source imported', [
            'source' => $source->path,
            'rows_parsed' => $result->rowsImported,
            'parsing_import_duration_ms' => $this->elapsedMilliseconds($parsingStartedAt),
            'total_import_duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $result;
    }

    /**
     * @return list<string>|null
     */
    private function allowedIsins(?string $override): ?array
    {
        $isins = [];

        foreach (explode(',', $override ?? (string) config('eix-pricing.import.isins')) as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $isins[Isin::from($value)->value] = true;
        }

        return $isins === [] ? null : array_keys($isins);
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    private function failImport(int $importId, Throwable $exception): void
    {
        $message = $exception instanceof UnexpectedValueException
            ? $exception->getMessage()
            : $exception::class;

        $this->imports->fail($importId, Str::limit($message, 1000, ''));
    }
}
