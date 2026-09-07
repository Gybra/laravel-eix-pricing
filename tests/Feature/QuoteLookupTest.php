<?php

declare(strict_types=1);

use Gybra\EixPricing\Application\QuoteLookup;
use Gybra\EixPricing\Application\QuoteNotFound;
use Gybra\EixPricing\Domain\Isin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
});

it('normalizes and validates ISINs', function (): void {
    expect(Isin::from(' ie000eofr2k5 ')->value)->toBe('IE000EOFR2K5');
});

it('rejects an invalid ISIN checksum', function (): void {
    expect(fn () => Isin::from('IE000EOFR2K6'))
        ->toThrow(InvalidArgumentException::class, 'Invalid ISIN');
});

it('returns the current quote from the configured connection', function (): void {
    $connection = DB::connection('package_testing');
    $now = '2026-09-07 20:41:00.000';
    $importId = $connection->table('eix_imports')->insertGetId([
        'source' => 'pretrade/source.csv.gz',
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

    $quote = app(QuoteLookup::class)->find('ie000eofr2k5');

    expect($quote->isin)->toBe('IE000EOFR2K5')
        ->and($quote->bid)->toBe('4.4615')
        ->and($quote->ask)->toBe('4.6235')
        ->and($quote->price)->toBe('4.5425')
        ->and($quote->quotedAt->format('Y-m-d H:i:s.v'))->toBe($now)
        ->and(Schema::connection('testing')->hasTable('eix_quotes'))->toBeFalse();
});

it('rejects a missing quote distinctly', function (): void {
    expect(fn () => app(QuoteLookup::class)->find('IE000EOFR2K5'))
        ->toThrow(QuoteNotFound::class);
});
