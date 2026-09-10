<?php

declare(strict_types=1);

use Gybra\EixPricing\Domain\SourceFile;
use Gybra\EixPricing\Infrastructure\Eix\EixSourceDownloader;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('eix-pricing.http.retries', 0);
});

it('streams a source to a local file without remote staging and removes it', function (): void {
    $contents = file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz');
    Http::fake(['*/api/trade-file-contents*' => Http::response($contents)]);
    Log::spy();
    $source = new SourceFile(
        'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz',
        1788759600000,
    );
    $localPath = null;

    $result = app(EixSourceDownloader::class)->withSource(
        $source,
        function (string $path) use (&$localPath, $contents): string {
            $localPath = $path;

            expect(is_file($path))->toBeTrue()
                ->and(file_get_contents($path))->toBe($contents);

            return 'processed';
        },
    );

    expect($result)->toBe('processed')
        ->and($localPath)->not->toBeNull()
        ->and(is_file($localPath))->toBeFalse();

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'EIX source downloaded'
            && $context['source'] === $source->path
            && $context['compressed_bytes'] === strlen($contents)
            && $context['download_duration_ms'] >= 0,
    );
});

it('retries transient download failures', function (): void {
    config()->set('eix-pricing.http.retries', 1);
    config()->set('eix-pricing.http.retry_delay_ms', 0);
    $contents = file_get_contents(__DIR__.'/../Fixtures/eix/pretrade.csv.gz');
    Http::fakeSequence()
        ->pushStatus(503)
        ->push($contents);
    $source = new SourceFile(
        'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz',
        1788759600000,
    );

    $result = app(EixSourceDownloader::class)->withSource(
        $source,
        fn (string $path): string => 'processed',
    );

    expect($result)->toBe('processed');
    Http::assertSentCount(2);
});

it('removes the local file when processing fails', function (): void {
    Http::fake(['*/api/trade-file-contents*' => Http::response('source')]);
    $source = new SourceFile(
        'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz',
        1788759600000,
    );
    $state = (object) ['localPath' => null];

    expect(fn () => app(EixSourceDownloader::class)->withSource(
        $source,
        function (string $path) use ($state): never {
            $state->localPath = $path;

            throw new RuntimeException('Processing failed.');
        },
    ))->toThrow(RuntimeException::class, 'Processing failed.');

    expect($state->localPath)->not->toBeNull()
        ->and(is_file($state->localPath))->toBeFalse();
});

it('removes the local file when the transfer fails', function (): void {
    Http::fake(['*/api/trade-file-contents*' => Http::response(status: 503)]);
    $source = new SourceFile(
        'pretrade/2026-09-07/Pretrade.1788759600000.csv.gz',
        1788759600000,
    );
    $temporaryFilesBefore = glob(sys_get_temp_dir().'/eix-*');

    expect(fn () => app(EixSourceDownloader::class)->withSource(
        $source,
        fn (): null => null,
    ))->toThrow(RequestException::class)
        ->and(glob(sys_get_temp_dir().'/eix-*'))->toBe($temporaryFilesBefore);
});
