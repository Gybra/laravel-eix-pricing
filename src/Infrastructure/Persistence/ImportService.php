<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence;

use Closure;
use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\ImportState;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Import;
use Illuminate\Support\Facades\Date;

final readonly class ImportService implements ImportServiceInterface
{
    public function findBySource(string $source): ?ImportState
    {
        $import = Import::query()
            ->where('source', $source)
            ->first(['id', 'status']);

        if ($import === null) {
            return null;
        }

        return new ImportState((int) $import->id, (string) $import->status);
    }

    public function start(string $source, ?int $existingId): int
    {
        return $this->transaction(function () use ($source, $existingId): int {
            $now = Date::now();
            $values = [
                'source' => $source,
                'status' => 'running',
                'rows_imported' => 0,
                'started_at' => $now,
                'finished_at' => null,
                'failure' => null,
                'updated_at' => $now,
            ];

            if ($existingId !== null) {
                Import::query()->where('id', $existingId)->update($values);

                return $existingId;
            }

            $values['created_at'] = $now;

            return (int) Import::query()->insertGetId($values);
        });
    }

    public function complete(int $id, int $rowsImported): void
    {
        $this->transaction(function () use ($id, $rowsImported): void {
            $now = Date::now();

            Import::query()->where('id', $id)->update([
                'status' => 'completed',
                'rows_imported' => $rowsImported,
                'finished_at' => $now,
                'failure' => null,
                'updated_at' => $now,
            ]);
        });
    }

    public function fail(int $id, string $failure): void
    {
        $this->transaction(function () use ($id, $failure): void {
            $now = Date::now();

            Import::query()->where('id', $id)->update([
                'status' => 'failed',
                'finished_at' => $now,
                'failure' => $failure,
                'updated_at' => $now,
            ]);
        });
    }

    public function pruneStale(): int
    {
        $cutoff = Date::now()->subDays(max(1, (int) config('eix-pricing.prune.retention_days')));

        return $this->transaction(
            fn (): int => Import::query()
                ->where('finished_at', '<=', $cutoff)
                ->delete(),
        );
    }

    public function transaction(Closure $callback): mixed
    {
        return Import::query()->getConnection()->transaction($callback);
    }
}
