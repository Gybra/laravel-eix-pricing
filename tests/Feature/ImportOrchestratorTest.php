<?php

declare(strict_types=1);

use Gybra\EixPricing\Application\ImportAlreadyRunning;
use Gybra\EixPricing\Application\ImportOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
    Storage::fake('eix-test');
    config()->set('eix-pricing.storage.disk', 'eix-test');
    config()->set('eix-pricing.http.retries', 0);
    config()->set('eix-pricing.import.lock_store', 'array');
    config()->set('eix-pricing.import.lock_name', 'eix-import-test');
    config()->set('eix-pricing.import.lock_seconds', 60);
    Cache::store('array')->flush();
    Http::preventStrayRequests();
});

function fakeSuccessfulImport(): void
{
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]),
        '*/api/trade-file-contents*' => Http::response(
            file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz'),
        ),
    ]);
}

it('imports a source and completes its metadata', function (): void {
    fakeSuccessfulImport();

    $result = app(ImportOrchestrator::class)->run();
    $import = DB::connection('package_testing')->table('eix_imports')->first();

    expect($result->skipped)->toBeFalse()
        ->and($result->rowsImported)->toBe(6)
        ->and($import->status)->toBe('completed')
        ->and($import->rows_imported)->toBe(6)
        ->and($import->finished_at)->not->toBeNull()
        ->and(DB::connection('package_testing')->table('eix_quotes')->count())->toBe(5);

    Storage::disk('eix-test')->assertMissing('eix/Pretrade.1788759600000.csv.gz');
});

it('skips a source that already completed', function (): void {
    fakeSuccessfulImport();
    app(ImportOrchestrator::class)->run();

    $result = app(ImportOrchestrator::class)->run();

    expect($result->skipped)->toBeTrue()
        ->and($result->rowsImported)->toBe(0)
        ->and(DB::connection('package_testing')->table('eix_imports')->count())->toBe(1);

    Http::assertSentCount(3);
});

it('rolls back quotes, marks failure, and retries the same source', function (): void {
    config()->set('eix-pricing.import.batch_size', 1);
    $invalidSource = implode("\n", [
        'Trading day & Trading time UTC,Instrument Identifier,Bid Quantity,Ask Quantity,Bid Price,Ask Price,Price Currency,Price Notation,Status',
        '2026-09-07T20:36:01.000Z,IE000EOFR2K5,1200,1200,4.461500,4.623500,EUR,MONE,TRAD',
        '2026-09-07T20:36:02.000Z,INVALID,1200,1200,4.461500,4.623500,EUR,MONE,TRAD',
        '',
    ]);
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]),
        '*/api/trade-file-contents*' => Http::sequence()
            ->push(gzencode($invalidSource))
            ->push(file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz')),
    ]);

    expect(fn () => app(ImportOrchestrator::class)->run())
        ->toThrow(UnexpectedValueException::class, 'row 3');

    $failedImport = DB::connection('package_testing')->table('eix_imports')->first();

    expect($failedImport->status)->toBe('failed')
        ->and($failedImport->failure)->toContain('row 3')
        ->and(DB::connection('package_testing')->table('eix_quotes')->count())->toBe(0);
    Storage::disk('eix-test')->assertMissing('eix/Pretrade.1788759600000.csv.gz');

    $result = app(ImportOrchestrator::class)->run();

    expect($result->skipped)->toBeFalse()
        ->and(DB::connection('package_testing')->table('eix_imports')->count())->toBe(1)
        ->and(DB::connection('package_testing')->table('eix_imports')->value('status'))->toBe('completed');
});

it('rejects overlapping imports before discovery', function (): void {
    $lock = Cache::store('array')->lock('eix-import-test', 60);
    $lock->get();

    try {
        expect(fn () => app(ImportOrchestrator::class)->run())
            ->toThrow(ImportAlreadyRunning::class);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});
