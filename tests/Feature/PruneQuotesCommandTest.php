<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
    Date::setTestNow('2026-09-08 01:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('skips pruning on Saturday and Sunday', function (): void {
    Date::setTestNow('2026-09-12 01:00:00');

    $this->artisan('eix:prune-quotes')
        ->expectsOutput('Skipped: markets are closed.')
        ->assertSuccessful();
});

it('deletes quotes older than the retention window and keeps recent ones', function (): void {
    $connection = DB::connection('package_testing');
    $importId = $connection->table('eix_imports')->insertGetId([
        'source' => 'pretrade/2026-09-06/Pretrade.1788584400000.csv.gz',
        'status' => 'completed',
        'rows_imported' => 2,
        'started_at' => '2026-09-06 01:00:00',
        'finished_at' => '2026-09-06 01:01:00',
        'created_at' => '2026-09-06 01:00:00',
        'updated_at' => '2026-09-06 01:01:00',
    ]);

    $connection->table('eix_quotes')->insert([
        [
            'import_id' => $importId,
            'isin' => 'IE000EOFR2K5',
            'bid' => '4.000000',
            'ask' => '5.000000',
            'price' => '4.5000000',
            'status' => 'TRAD',
            'quoted_at' => '2026-09-06 00:50:00',
            'source_timestamp' => 1788584400000,
            'source_row' => 1,
            'imported_at' => '2026-09-06 01:00:00',
        ],
        [
            'import_id' => $importId,
            'isin' => 'LU1094612022',
            'bid' => '19.000000',
            'ask' => '21.000000',
            'price' => '20.0000000',
            'status' => 'TRAD',
            'quoted_at' => '2026-09-08 00:50:00',
            'source_timestamp' => 1788759600000,
            'source_row' => 2,
            'imported_at' => '2026-09-08 00:50:00',
        ],
    ]);

    $this->artisan('eix:prune-quotes')
        ->expectsOutput('Deleted 1 stale quote.')
        ->assertSuccessful();

    $remaining = $connection->table('eix_quotes')->pluck('isin');

    expect($remaining->all())->toBe(['LU1094612022']);
});
