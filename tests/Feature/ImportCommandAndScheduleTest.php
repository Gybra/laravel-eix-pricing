<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
    Http::preventStrayRequests();
});

function scheduledEixImport(): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command ?? '', 'eix:import')) {
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
        ->expectsOutput('Imported 6 rows from pretrade/2026-09-07/Pretrade.1788759600000.csv.gz.')
        ->assertSuccessful();

    $this->artisan('eix:import')
        ->expectsOutput('Source pretrade/2026-09-07/Pretrade.1788759600000.csv.gz was already imported.')
        ->assertSuccessful();
});

it('schedules imports with the configured cadence and overlap protection', function (): void {
    $event = scheduledEixImport();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(180)
        ->and($event->onOneServer)->toBeFalse();
});

it('opts into single-server scheduling when configured', function (): void {
    config()->set('eix-pricing.schedule.on_one_server', true);

    expect(scheduledEixImport()?->onOneServer)->toBeTrue();
});

it('does not schedule imports when disabled', function (): void {
    config()->set('eix-pricing.schedule.enabled', false);

    expect(scheduledEixImport())->toBeNull();
});
