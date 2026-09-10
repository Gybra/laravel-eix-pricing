<?php

declare(strict_types=1);

use Gybra\EixPricing\Application\ImportAlreadyRunning;
use Gybra\EixPricing\Application\ImportOrchestrator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
    config()->set('eix-pricing.http.retries', 0);
    config()->set('eix-pricing.import.lock_store', 'array');
    config()->set('eix-pricing.import.lock_name', 'eix-import-test');
    config()->set('eix-pricing.import.lock_seconds', 60);
    config()->set('eix-pricing.import.lookback_minutes', 30);
    Date::setTestNow(Date::createFromTimestampMs(1_788_759_600_000));
    Cache::store('array')->flush();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Date::setTestNow();
});

function fakeSuccessfulImport(): void
{
    $fixture = file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz');

    Http::fake(function (Request $request) use ($fixture) {
        if (str_contains($request->url(), 'trade-files')) {
            return Http::response([
                ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
            ]);
        }

        return Http::response($fixture);
    });
}

it('imports a source, completes its metadata, and logs timing metrics', function (): void {
    fakeSuccessfulImport();
    Log::spy();

    $results = app(ImportOrchestrator::class)->run();
    $import = DB::connection('package_testing')->table('eix_imports')->first();

    expect($results)->toHaveCount(1)
        ->and($results[0]->skipped)->toBeFalse()
        ->and($results[0]->rowsImported)->toBe(6)
        ->and($import->status)->toBe('completed')
        ->and($import->rows_imported)->toBe(6)
        ->and($import->finished_at)->not->toBeNull()
        ->and(DB::connection('package_testing')->table('eix_quotes')->count())->toBe(5);

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context): bool => $message === 'EIX source imported'
            && $context['source'] === 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'
            && $context['rows_parsed'] === 6
            && $context['parsing_import_duration_ms'] >= 0
            && $context['total_import_duration_ms'] >= $context['parsing_import_duration_ms'],
    )->once();
});

it('imports every uncompleted source in the lookback window', function (): void {
    $fixture = file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz');
    Http::fake(function (Request $request) use ($fixture) {
        if (str_contains($request->url(), 'trade-files')) {
            return Http::response([
                ['fileName' => 'pretrade/2026-09-07/Pretrade.1788757200000.csv.gz'],
                ['fileName' => 'pretrade/2026-09-07/Pretrade.1788758400000.csv.gz'],
                ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
            ]);
        }

        return Http::response($fixture);
    });

    $results = app(ImportOrchestrator::class)->run();
    $sources = DB::connection('package_testing')
        ->table('eix_imports')
        ->orderBy('source')
        ->pluck('source');

    expect($results)->toHaveCount(2)
        ->and($results[0]->source)->toBe('pretrade/2026-09-07/Pretrade.1788758400000.csv.gz')
        ->and($results[1]->source)->toBe('pretrade/2026-09-07/Pretrade.1788759600000.csv.gz')
        ->and($sources->all())->toBe([
            'pretrade/2026-09-07/Pretrade.1788758400000.csv.gz',
            'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz',
        ]);
});

it('imports only the configured ISINs', function (): void {
    fakeSuccessfulImport();
    config()->set('eix-pricing.import.isins', 'IE000EOFR2K5, ie00bmtm6b32');

    $results = app(ImportOrchestrator::class)->run();

    expect($results[0]->rowsImported)->toBe(3)
        ->and(DB::connection('package_testing')->table('eix_imports')->value('status'))->toBe('completed')
        ->and(DB::connection('package_testing')->table('eix_quotes')->orderBy('isin')->pluck('isin')->all())
        ->toBe(['IE000EOFR2K5', 'IE00BMTM6B32']);
});

it('does not complete a source when an ISIN override is passed', function (): void {
    fakeSuccessfulImport();

    $filtered = app(ImportOrchestrator::class)->run('IE000EOFR2K5');
    $import = DB::connection('package_testing')->table('eix_imports')->first();

    expect($filtered[0]->rowsImported)->toBe(1)
        ->and($import->status)->toBe('running')
        ->and(DB::connection('package_testing')->table('eix_quotes')->pluck('isin')->all())
        ->toBe(['IE000EOFR2K5']);

    $full = app(ImportOrchestrator::class)->run();

    expect($full[0]->skipped)->toBeFalse()
        ->and($full[0]->rowsImported)->toBe(6)
        ->and(DB::connection('package_testing')->table('eix_imports')->value('status'))->toBe('completed')
        ->and(DB::connection('package_testing')->table('eix_quotes')->count())->toBe(5);
});

it('rejects invalid configured import ISINs before discovery', function (): void {
    Http::fake();
    config()->set('eix-pricing.import.isins', 'NOTANISIN');

    expect(fn () => app(ImportOrchestrator::class)->run())
        ->toThrow(InvalidArgumentException::class, 'Invalid ISIN.');

    Http::assertNothingSent();
});

it('skips a source that already completed', function (): void {
    fakeSuccessfulImport();
    app(ImportOrchestrator::class)->run();

    $results = app(ImportOrchestrator::class)->run();

    expect($results)->toHaveCount(1)
        ->and($results[0]->skipped)->toBeTrue()
        ->and($results[0]->rowsImported)->toBe(0)
        ->and(DB::connection('package_testing')->table('eix_imports')->count())->toBe(1);

    Http::assertSentCount(3);
});

it('does not discover or import on weekends', function (): void {
    Date::setTestNow('2026-09-12 10:00:00');
    Http::fake();

    expect(app(ImportOrchestrator::class)->run())->toBe([]);
    Http::assertNothingSent();
});

it('returns no results when the lookback window is empty', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788757200000.csv.gz'],
        ]),
    ]);

    expect(app(ImportOrchestrator::class)->run())->toBe([])
        ->and(DB::connection('package_testing')->table('eix_imports')->count())->toBe(0);
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

    $results = app(ImportOrchestrator::class)->run();

    expect($results[0]->skipped)->toBeFalse()
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
