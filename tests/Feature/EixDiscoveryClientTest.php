<?php

declare(strict_types=1);

use Gybra\EixPricing\Infrastructure\Eix\EixDiscoveryClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('eix-pricing.http.retries', 0);
    config()->set('eix-pricing.http.retry_delay_ms', 0);
    config()->set('eix-pricing.import.lookback_minutes', 30);
    Date::setTestNow(Date::createFromTimestampMs(1_788_759_600_000));
});

afterEach(function (): void {
    Date::setTestNow();
});

it('returns lookback sources oldest first and ignores older files', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788757200000.csv.gz'],
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788758400000.csv.gz'],
        ]),
    ]);

    $sources = app(EixDiscoveryClient::class)->recent();

    expect($sources)->toHaveCount(2)
        ->and($sources[0]->path)->toBe('pretrade/2026-09-07/Pretrade.1788758400000.csv.gz')
        ->and($sources[1]->path)->toBe('pretrade/2026-09-07/Pretrade.1788759600000.csv.gz');
});

it('returns no sources when none fall inside the lookback window', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788757200000.csv.gz'],
        ]),
    ]);

    expect(app(EixDiscoveryClient::class)->recent())->toBe([]);
});

it('rejects an empty discovery response', function (): void {
    Http::fake([config('eix-pricing.discovery_url') => Http::response([])]);

    expect(fn () => app(EixDiscoveryClient::class)->recent())
        ->toThrow(UnexpectedValueException::class, 'no source files');
});

it('rejects malformed discovery entries', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'not-a-pretrade-source.zip'],
        ]),
    ]);

    expect(fn () => app(EixDiscoveryClient::class)->recent())
        ->toThrow(UnexpectedValueException::class, 'Malformed EIX discovery entry');
});

it('throws for unsuccessful discovery responses', function (): void {
    Http::fake([config('eix-pricing.discovery_url') => Http::response(status: 503)]);

    expect(fn () => app(EixDiscoveryClient::class)->recent())
        ->toThrow(RequestException::class);
});

it('retries transient discovery failures', function (): void {
    config()->set('eix-pricing.http.retries', 1);
    Http::fakeSequence()
        ->pushStatus(503)
        ->push([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]);

    expect(app(EixDiscoveryClient::class)->recent()[0]->timestampMilliseconds)
        ->toBe(1788759600000);

    Http::assertSentCount(2);
});
