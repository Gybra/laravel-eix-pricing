<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();

    $this->artisan('migrate:fresh')->assertSuccessful();
});

it('creates the persistence schema on the configured connection', function (): void {
    expect(Schema::connection('package_testing')->hasColumns('eix_imports', [
        'id',
        'source',
        'status',
        'rows_imported',
        'started_at',
        'finished_at',
        'failure',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::connection('package_testing')->hasColumns('eix_quotes', [
            'id',
            'import_id',
            'isin',
            'bid',
            'ask',
            'price',
            'status',
            'quoted_at',
            'source_timestamp',
            'source_row',
            'imported_at',
        ]))->toBeTrue()
        ->and(Schema::connection('testing')->hasTable('eix_imports'))->toBeFalse();
});

it('enforces unique import sources and current ISINs', function (): void {
    $connection = DB::connection('package_testing');
    $now = '2026-09-07 20:41:00.000';

    $importId = $connection->table('eix_imports')->insertGetId([
        'source' => 'pretrade/2026-09-07/Pretrade.1788813600000.csv.gz',
        'status' => 'running',
        'rows_imported' => 0,
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => $connection->table('eix_imports')->insert([
        'source' => 'pretrade/2026-09-07/Pretrade.1788813600000.csv.gz',
        'status' => 'running',
        'rows_imported' => 0,
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]))->toThrow(QueryException::class);

    $quote = [
        'import_id' => $importId,
        'isin' => 'IE000EOFR2K5',
        'bid' => '4.461500',
        'ask' => '4.623500',
        'price' => '4.5425000',
        'status' => 'TRAD',
        'quoted_at' => $now,
        'source_timestamp' => 1788813600000,
        'source_row' => 1,
        'imported_at' => $now,
    ];

    $connection->table('eix_quotes')->insert($quote);

    expect(fn () => $connection->table('eix_quotes')->insert($quote))
        ->toThrow(QueryException::class);
});

it('rolls back both persistence tables', function (): void {
    $this->artisan('migrate:rollback')->assertSuccessful();

    expect(Schema::connection('package_testing')->hasTable('eix_quotes'))->toBeFalse()
        ->and(Schema::connection('package_testing')->hasTable('eix_imports'))->toBeFalse();
});
