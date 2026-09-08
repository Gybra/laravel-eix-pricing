<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence;

use Gybra\EixPricing\Domain\QuoteRecord;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Quote as QuoteModel;
use Illuminate\Database\Connection;

final readonly class QuoteWriter
{
    /**
     * @param  iterable<QuoteRecord>  $records
     */
    public function write(iterable $records, int $importId, int $sourceTimestamp): int
    {
        $model = new QuoteModel;
        $connection = $model->getConnection();
        $table = $connection->getQueryGrammar()->wrapTable($model->getTable());

        return $connection->transaction(function () use ($connection, $table, $records, $importId, $sourceTimestamp): int {
            $batch = [];
            $written = 0;
            $batchSize = max(1, (int) config('eix-pricing.import.batch_size'));

            foreach ($records as $record) {
                $batch[] = $record;
                $written++;

                if (count($batch) === $batchSize) {
                    $this->upsert($connection, $table, $batch, $importId, $sourceTimestamp);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->upsert($connection, $table, $batch, $importId, $sourceTimestamp);
            }

            return $written;
        });
    }

    /**
     * @param  list<QuoteRecord>  $records
     */
    private function upsert(
        Connection $connection,
        string $table,
        array $records,
        int $importId,
        int $sourceTimestamp,
    ): void {
        $records = $this->latestPerIsin($records);
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
                $record->quotedAt->format('Y-m-d H:i:s.vP'),
                $sourceTimestamp,
                $record->sourceRow,
            );
        }

        $connection->statement(sprintf($this->insertSql($table), $placeholders), $bindings);
    }

    private function insertSql(string $table): string
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
