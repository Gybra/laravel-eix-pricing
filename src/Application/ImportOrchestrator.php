<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Domain\ImportResult;
use Gybra\EixPricing\Domain\SourceFile;
use Gybra\EixPricing\Infrastructure\Eix\EixCsvParser;
use Gybra\EixPricing\Infrastructure\Eix\EixDiscoveryClient;
use Gybra\EixPricing\Infrastructure\Eix\EixSourceStager;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Date;
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
        private EixSourceStager $stager,
        private EixCsvParser $parser,
    ) {}

    /**
     * @return list<ImportResult>
     */
    public function run(): array
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
            $results = [];

            foreach ($this->discovery->recent() as $source) {
                $results[] = $this->import($source);
            }

            return $results;
        } finally {
            $lock->release();
        }
    }

    private function import(SourceFile $source): ImportResult
    {
        $existing = $this->imports->findBySource($source->path);

        if ($existing?->status === 'completed') {
            return new ImportResult($source->path, 0, true);
        }

        $importId = $this->imports->start($source->path, $existing?->id);

        try {
            return $this->stager->withSource(
                $source,
                fn (string $path): ImportResult => $this->persist($source, $importId, $path),
            );
        } catch (Throwable $exception) {
            try {
                $this->failImport($importId, $exception);
            } catch (Throwable $metadataException) {
                report($metadataException);
            }

            throw $exception;
        }
    }

    private function persist(SourceFile $source, int $importId, string $path): ImportResult
    {
        return $this->imports->transaction(function () use ($source, $importId, $path): ImportResult {
            $rowsImported = $this->quotes->write(
                $this->parser->records($path),
                $importId,
                $source->timestampMilliseconds,
            );

            $this->imports->complete($importId, $rowsImported);

            return new ImportResult($source->path, $rowsImported, false);
        });
    }

    private function failImport(int $importId, Throwable $exception): void
    {
        $message = $exception instanceof UnexpectedValueException
            ? $exception->getMessage()
            : $exception::class;

        $this->imports->fail($importId, Str::limit($message, 1000, ''));
    }
}
