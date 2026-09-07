<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
});

function seedApiQuote(): void
{
    $connection = DB::connection('package_testing');
    $now = '2026-09-07 20:41:00.000';
    $importId = $connection->table('eix_imports')->insertGetId([
        'source' => 'pretrade/api.csv.gz',
        'status' => 'completed',
        'rows_imported' => 1,
        'started_at' => $now,
        'finished_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $connection->table('eix_quotes')->insert([
        'import_id' => $importId,
        'isin' => 'IE000EOFR2K5',
        'bid' => '4.461500',
        'ask' => '4.623500',
        'price' => '4.5425000',
        'status' => 'TRAD',
        'quoted_at' => $now,
        'source_timestamp' => 1788759600000,
        'source_row' => 2,
        'imported_at' => $now,
    ]);
}

it('returns the exact quote contract for a normalized ISIN', function (): void {
    seedApiQuote();

    $this->getJson('/api/quotes/ie000eofr2k5')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'isin' => 'IE000EOFR2K5',
                'price' => 4.5425,
                'bid' => 4.4615,
                'ask' => 4.6235,
                'quoted_at' => '2026-09-07T20:41:00.000000Z',
            ],
        ]);
});

it('returns 404 for a missing quote', function (): void {
    $this->getJson('/api/quotes/IE000EOFR2K5')->assertNotFound();
});

it('returns 422 for an invalid ISIN', function (): void {
    $this->getJson('/api/quotes/IE000EOFR2K6')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('isin');
});

it('throttles quote requests', function (): void {
    seedApiQuote();

    $this->getJson('/api/quotes/IE000EOFR2K5')->assertOk();
    $this->getJson('/api/quotes/IE000EOFR2K5')->assertOk();
    $this->getJson('/api/quotes/IE000EOFR2K5')->assertTooManyRequests();
});
