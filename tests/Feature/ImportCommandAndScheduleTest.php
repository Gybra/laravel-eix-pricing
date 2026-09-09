<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
    config()->set('eix-pricing.http.retries', 0);
    config()->set('eix-pricing.import.lock_store', 'array');
    config()->set('eix-pricing.import.lookback_minutes', 30);
    Date::setTestNow(Date::createFromTimestampMs(1_788_759_600_000));
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Date::setTestNow();
});

function scheduledCommand(string $signature): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command ?? '', $signature)) {
            return $event;
        }
    }

    return null;
}

it('runs and idempotently skips imports through Artisan', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]),
        '*/api/trade-file-contents*' => Http::response(
            file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz'),
        ),
    ]);

    $this->artisan('eix:import')
        ->expectsOutputToContain('Discovering EIX sources from the last 30 minutes...')
        ->expectsOutputToContain('Found 1 source.')
        ->expectsOutputToContain('[1/1] pretrade/2026-09-07/Pretrade.1788759600000.csv.gz')
        ->expectsOutputToContain('Downloading compressed source...')
        ->expectsOutputToContain('Downloaded')
        ->expectsOutputToContain('Parsing CSV and writing quote batches...')
        ->expectsOutputToContain('Wrote batch 1:')
        ->expectsOutputToContain('Finished source:')
        ->expectsOutput('Imported 6 rows from pretrade/2026-09-07/Pretrade.1788759600000.csv.gz.')
        ->assertSuccessful();

    $this->artisan('eix:import')
        ->expectsOutput('Source pretrade/2026-09-07/Pretrade.1788759600000.csv.gz was already imported.')
        ->assertSuccessful();
});

it('imports only ISINs passed to the command', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]),
        '*/api/trade-file-contents*' => Http::response(
            file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz'),
        ),
    ]);
    config()->set('eix-pricing.import.isins', 'LU1094612022');

    $this->artisan('eix:import', ['--isins' => 'IE000EOFR2K5'])
        ->expectsOutputToContain('Filtering to 1 ISIN.')
        ->expectsOutput('Imported 1 rows from pretrade/2026-09-07/Pretrade.1788759600000.csv.gz.')
        ->assertSuccessful();
});

it('skips imports on Saturday and Sunday', function (): void {
    Date::setTestNow('2026-09-12 10:00:00');
    Http::fake();

    $this->artisan('eix:import')
        ->expectsOutput('Skipped: markets are closed.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('reports when no sources fall inside the lookback window', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788757200000.csv.gz'],
        ]),
    ]);

    $this->artisan('eix:import')
        ->expectsOutput('No EIX sources in the lookback window.')
        ->assertSuccessful();
});

it('reports an overlapping import without an exception trace', function (): void {
    $lock = Cache::store('array')->lock((string) config('eix-pricing.import.lock_name'), 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('eix:import')
            ->expectsOutput('An EIX import is already running.')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

it('schedules imports with the configured cadence and overlap protection', function (): void {
    $event = scheduledCommand('eix:import');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * 1-5')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->expiresAt)->toBe(180)
        ->and($event->onOneServer)->toBeFalse();
});

it('schedules nightly quote pruning', function (): void {
    $event = scheduledCommand('eix:prune-quotes');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1 * * 1-5')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('opts into single-server scheduling when configured', function (): void {
    config()->set('eix-pricing.schedule.on_one_server', true);

    expect(scheduledCommand('eix:import')?->onOneServer)->toBeTrue()
        ->and(scheduledCommand('eix:prune-quotes')?->onOneServer)->toBeTrue();
});

it('does not schedule imports or pruning when disabled', function (): void {
    config()->set('eix-pricing.schedule.enabled', false);

    expect(scheduledCommand('eix:import'))->toBeNull()
        ->and(scheduledCommand('eix:prune-quotes'))->toBeNull();
});
