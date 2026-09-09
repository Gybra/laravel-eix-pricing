<?php

declare(strict_types=1);

use Gybra\EixPricing\Infrastructure\Persistence\Models\Import;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Quote;
use Illuminate\Support\Facades\Date;
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
    $import = Import::factory()->create();

    Quote::factory()->create([
        'import_id' => $import->id,
        'isin' => 'IE000EOFR2K5',
        'imported_at' => '2026-09-06 01:00:00',
    ]);
    Quote::factory()->create([
        'import_id' => $import->id,
        'isin' => 'LU1094612022',
        'imported_at' => '2026-09-08 00:50:00',
    ]);

    $this->artisan('eix:prune-quotes')
        ->expectsOutput('Deleted 1 stale quote.')
        ->assertSuccessful();

    expect(Quote::query()->pluck('isin')->all())->toBe(['LU1094612022']);
});

it('deletes imports older than the retention window and keeps recent ones', function (): void {
    Import::factory()->create([
        'started_at' => '2026-09-05 01:00:00',
        'finished_at' => '2026-09-05 01:05:00',
    ]);
    $recent = Import::factory()->create([
        'started_at' => '2026-09-07 01:00:00',
        'finished_at' => '2026-09-07 01:05:00',
    ]);

    $this->artisan('eix:prune-quotes')
        ->expectsOutput('Deleted 1 stale import.')
        ->assertSuccessful();

    expect(Import::query()->pluck('id')->all())->toBe([$recent->id]);
});

it('does not prune a running import', function (): void {
    $running = Import::factory()->create([
        'status' => 'running',
        'started_at' => '2026-09-05 01:00:00',
        'finished_at' => null,
    ]);

    $this->artisan('eix:prune-quotes')
        ->expectsOutput('Deleted 0 stale imports.')
        ->assertSuccessful();

    expect(Import::query()->whereKey($running->id)->exists())->toBeTrue();
});
