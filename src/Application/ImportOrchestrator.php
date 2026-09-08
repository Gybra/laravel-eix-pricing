<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Gybra\EixPricing\Domain\ImportResult;
use Gybra\EixPricing\Domain\SourceFile;
use Gybra\EixPricing\Infrastructure\Eix\EixCsvParser;
use Gybra\EixPricing\Infrastructure\Eix\EixDiscoveryClient;
use Gybra\EixPricing\Infrastructure\Eix\EixSourceStager;
use Gybra\EixPricing\Infrastructure\Persistence\QuoteWriter;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class ImportOrchestrator
{
    public function __construct(
        private CacheManager $cache,
        private DatabaseManager $database,
        private EixDiscoveryClient $discovery,
        private EixSourceStager $stager,
        private EixCsvParser $parser,
        private QuoteWriter $writer,
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
        $connection = $this->connection();
        $existing = $connection->table('eix_imports')
            ->where('source', $source->path)
            ->first(['id', 'status']);

        if ($existing?->status === 'completed') {
            return new ImportResult($source->path, 0, true);
        }

        $importId = $this->startImport($connection, $source, $existing?->id);

        try {
            return $this->stager->withSource(
                $source,
                fn (string $path): ImportResult => $this->persist($connection, $source, $importId, $path),
            );
        } catch (Throwable $exception) {
            try {
                $this->failImport($connection, $importId, $exception);
            } catch (Throwable $metadataException) {
                report($metadataException);
            }

            throw $exception;
        }
    }

    private function startImport(Connection $connection, SourceFile $source, mixed $existingId): int
    {
        return $connection->transaction(function () use ($connection, $source, $existingId): int {
            $now = Date::now();
            $values = [
                'source' => $source->path,
                'status' => 'running',
                'rows_imported' => 0,
                'started_at' => $now,
                'finished_at' => null,
                'failure' => null,
                'updated_at' => $now,
            ];

            if ($existingId !== null) {
                $connection->table('eix_imports')->where('id', $existingId)->update($values);

                return (int) $existingId;
            }

            return $connection->table('eix_imports')->insertGetId([
                ...$values,
                'created_at' => $now,
            ]);
        });
    }

    private function persist(
        Connection $connection,
        SourceFile $source,
        int $importId,
        string $path,
    ): ImportResult {
        return $connection->transaction(function () use ($connection, $source, $importId, $path): ImportResult {
            $rowsImported = $this->writer->write(
                $this->parser->records($path),
                $importId,
                $source->timestampMilliseconds,
            );

            $connection->table('eix_imports')->where('id', $importId)->update([
                'status' => 'completed',
                'rows_imported' => $rowsImported,
                'finished_at' => Date::now(),
                'failure' => null,
                'updated_at' => Date::now(),
            ]);

            return new ImportResult($source->path, $rowsImported, false);
        });
    }

    private function failImport(Connection $connection, int $importId, Throwable $exception): void
    {
        $connection->transaction(function () use ($connection, $importId, $exception): void {
            $now = Date::now();
            $message = $exception instanceof UnexpectedValueException
                ? $exception->getMessage()
                : $exception::class;

            $connection->table('eix_imports')->where('id', $importId)->update([
                'status' => 'failed',
                'finished_at' => $now,
                'failure' => Str::limit($message, 1000, ''),
                'updated_at' => $now,
            ]);
        });
    }

    private function connection(): Connection
    {
        return $this->database->connection(config('eix-pricing.database.connection'));
    }
}
