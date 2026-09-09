<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Gybra\EixPricing\Domain\QuoteRecord;
use Gybra\EixPricing\Infrastructure\Console\ProgressReporter;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Quote as QuoteModel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

final readonly class QuoteWriter
{
    public function __construct(private ?ProgressReporter $progress = null) {}

    /**
     * @param  iterable<QuoteRecord>  $records
     */
    public function write(iterable $records, int $importId, int $sourceTimestamp): int
    {
        $model = new QuoteModel;
        $connection = $model->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable($model->getTable());

        $batch = [];
        $rowsParsed = 0;
        $rowsSubmitted = 0;
        $databaseBatches = 0;
        $databaseWriteDuration = 0.0;
        $batchSize = max(1, (int) config('eix-pricing.import.batch_size'));

        foreach ($records as $record) {
            $batch[] = $record;
            $rowsParsed++;

            if (count($batch) === $batchSize) {
                [$submitted, $duration] = $this->writeBatch(
                    $connection,
                    $table,
                    $batch,
                    $importId,
                    $sourceTimestamp,
                    $databaseBatches + 1,
                    $rowsParsed,
                );
                $rowsSubmitted += $submitted;
                $databaseWriteDuration += $duration;
                $databaseBatches++;
                $batch = [];
            }
        }

        if ($batch !== []) {
            [$submitted, $duration] = $this->writeBatch(
                $connection,
                $table,
                $batch,
                $importId,
                $sourceTimestamp,
                $databaseBatches + 1,
                $rowsParsed,
            );
            $rowsSubmitted += $submitted;
            $databaseWriteDuration += $duration;
            $databaseBatches++;
        }

        $databaseWriteDuration = round($databaseWriteDuration, 3);

        Log::info('EIX quote batches written', [
            'import_id' => $importId,
            'rows_parsed' => $rowsParsed,
            'rows_submitted' => $rowsSubmitted,
            'database_batches' => $databaseBatches,
            'database_write_duration_ms' => $databaseWriteDuration,
        ]);

        return $rowsParsed;
    }

    /**
     * @param  list<QuoteRecord>  $records
     * @return array{int, float}
     */
    private function writeBatch(
        Connection $connection,
        string $table,
        array $records,
        int $importId,
        int $sourceTimestamp,
        int $batchNumber,
        int $rowsParsed,
    ): array {
        $batchCount = count($records);
        [$submitted, $duration] = $this->upsert($connection, $table, $records, $importId, $sourceTimestamp);

        if ($this->progress !== null) {
            $this->progress->say(sprintf(
                'Wrote batch %d: %d rows (%d unique) in %s. %d rows so far.',
                $batchNumber,
                $batchCount,
                $submitted,
                $this->progress->duration($duration),
                $rowsParsed,
            ));
        }

        return [$submitted, $duration];
    }

    /**
     * @param  list<QuoteRecord>  $records
     * @return array{int, float}
     */
    private function upsert(
        Connection $connection,
        string $table,
        array $records,
        int $importId,
        int $sourceTimestamp,
    ): array {
        $records = $this->latestPerIsin($records);
        $driver = $connection->getDriverName();
        $placeholders = implode(', ', array_fill(
            0,
            count($records),
            '(?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
        ));
        $bindings = [];

        foreach ($records as $record) {
            array_push(
                $bindings,
                $importId,
                $record->isin,
                $record->bid,
                $record->ask,
                $record->price,
                $record->status,
                $this->quotedAtValue($record->quotedAt, $driver),
                $sourceTimestamp,
                $record->sourceRow,
            );
        }

        $startedAt = hrtime(true);
        $connection->statement(sprintf($this->insertSql($table, $driver), $placeholders), $bindings);

        return [count($records), (hrtime(true) - $startedAt) / 1_000_000];
    }

    private function quotedAtValue(DateTimeImmutable $quotedAt, string $driver): string
    {
        $utc = $quotedAt->setTimezone(new DateTimeZone('UTC'));

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $utc->format('Y-m-d H:i:s.v');
        }

        return $utc->format('Y-m-d H:i:s.vP');
    }

    private function insertSql(string $table, string $driver): string
    {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $this->duplicateKeyInsertSql($table);
        }

        return $this->conflictInsertSql($table);
    }

    private function conflictInsertSql(string $table): string
    {
        return <<<SQL
            INSERT INTO {$table} (
                import_id, isin, bid, ask, price, status, quoted_at,
                source_timestamp, source_row, imported_at
            ) VALUES %s
            ON CONFLICT (isin) DO UPDATE SET
                import_id = excluded.import_id,
                bid = excluded.bid,
                ask = excluded.ask,
                price = excluded.price,
                status = excluded.status,
                quoted_at = excluded.quoted_at,
                source_timestamp = excluded.source_timestamp,
                source_row = excluded.source_row,
                imported_at = excluded.imported_at
            WHERE excluded.quoted_at > {$table}.quoted_at
               OR (excluded.quoted_at = {$table}.quoted_at
                   AND excluded.source_timestamp > {$table}.source_timestamp)
               OR (excluded.quoted_at = {$table}.quoted_at
                   AND excluded.source_timestamp = {$table}.source_timestamp
                   AND excluded.source_row > {$table}.source_row)
            SQL;
    }

    private function duplicateKeyInsertSql(string $table): string
    {
        $newer = "VALUES(quoted_at) > {$table}.quoted_at"
            ." OR (VALUES(quoted_at) = {$table}.quoted_at AND VALUES(source_timestamp) > {$table}.source_timestamp)"
            ." OR (VALUES(quoted_at) = {$table}.quoted_at AND VALUES(source_timestamp) = {$table}.source_timestamp AND VALUES(source_row) > {$table}.source_row)";

        $assignments = [];

        foreach (['import_id', 'bid', 'ask', 'price', 'status', 'quoted_at', 'source_timestamp', 'source_row', 'imported_at'] as $column) {
            $assignments[] = "{$column} = IF({$newer}, VALUES({$column}), {$table}.{$column})";
        }

        $set = implode(",\n                ", $assignments);

        return <<<SQL
            INSERT INTO {$table} (
                import_id, isin, bid, ask, price, status, quoted_at,
                source_timestamp, source_row, imported_at
            ) VALUES %s
            ON DUPLICATE KEY UPDATE
                {$set}
            SQL;
    }

    /**
     * @param  list<QuoteRecord>  $records
     * @return list<QuoteRecord>
     */
    private function latestPerIsin(array $records): array
    {
        $latest = [];

        foreach ($records as $record) {
            $current = $latest[$record->isin] ?? null;

            if ($current === null
                || $record->quotedAt > $current->quotedAt
                || ($record->quotedAt == $current->quotedAt && $record->sourceRow > $current->sourceRow)
            ) {
                $latest[$record->isin] = $record;
            }
        }

        return array_values($latest);
    }
}
