<?php

declare(strict_types=1);

use Gybra\EixPricing\Infrastructure\Eix\EixDiscoveryClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('eix-pricing.http.retries', 0);
    config()->set('eix-pricing.http.retry_delay_ms', 0);
});

it('selects the newest valid source independently of response order', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759300000.csv.gz'],
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759000000.csv.gz'],
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]),
    ]);

    $source = app(EixDiscoveryClient::class)->newest();

    expect($source->path)
        ->toBe('pretrade/2026-09-07/Pretrade.1788759600000.csv.gz')
        ->and($source->timestampMilliseconds)->toBe(1788759600000);
});

it('rejects an empty discovery response', function (): void {
    Http::fake([config('eix-pricing.discovery_url') => Http::response([])]);

    expect(fn () => app(EixDiscoveryClient::class)->newest())
        ->toThrow(UnexpectedValueException::class, 'no source files');
});

it('rejects malformed discovery entries', function (): void {
    Http::fake([
        config('eix-pricing.discovery_url') => Http::response([
            ['fileName' => 'not-a-pretrade-source.zip'],
        ]),
    ]);

    expect(fn () => app(EixDiscoveryClient::class)->newest())
        ->toThrow(UnexpectedValueException::class, 'Malformed EIX discovery entry');
});

it('throws for unsuccessful discovery responses', function (): void {
    Http::fake([config('eix-pricing.discovery_url') => Http::response(status: 503)]);

    expect(fn () => app(EixDiscoveryClient::class)->newest())
        ->toThrow(RequestException::class);
});

it('retries transient discovery failures', function (): void {
    config()->set('eix-pricing.http.retries', 1);
    Http::fakeSequence()
        ->pushStatus(503)
        ->push([
            ['fileName' => 'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz'],
        ]);

    expect(app(EixDiscoveryClient::class)->newest()->timestampMilliseconds)
        ->toBe(1788759600000);

    Http::assertSentCount(2);
});
