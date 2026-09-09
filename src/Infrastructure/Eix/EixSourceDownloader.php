<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Closure;
use Gybra\EixPricing\Domain\SourceFile;
use Gybra\EixPricing\Infrastructure\Console\ProgressReporter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use RuntimeException;

final readonly class EixSourceDownloader
{
    public function __construct(
        private Factory $http,
        private ProgressReporter $progress,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $process
     * @return TResult
     */
    public function withSource(SourceFile $source, Closure $process): mixed
    {
        $localPath = tempnam(sys_get_temp_dir(), 'eix-');

        if ($localPath === false) {
            throw new RuntimeException('Unable to create a temporary EIX source file.');
        }

        try {
            $startedAt = hrtime(true);
            $this->progress->say('Downloading compressed source...');
            $this->download($source, $localPath);

            $bytes = filesize($localPath);
            $duration = $this->elapsedMilliseconds($startedAt);
            $size = is_int($bytes) ? Number::fileSize($bytes) : 'unknown size';

            Log::info('EIX source downloaded', [
                'source' => $source->path,
                'compressed_bytes' => $bytes,
                'download_duration_ms' => $duration,
            ]);
            $this->progress->say("Downloaded {$size} in {$this->progress->duration($duration)}.");
            $this->progress->say('Parsing CSV and writing quote batches...');

            return $process($localPath);
        } finally {
            if (is_file($localPath)) {
                unlink($localPath);
            }
        }
    }

    private function download(SourceFile $source, string $localPath): void
    {
        $this->http
            ->connectTimeout((int) config('eix-pricing.http.connect_timeout'))
            ->timeout((int) config('eix-pricing.http.download_timeout'))
            ->retry(
                (int) config('eix-pricing.http.retries') + 1,
                fn (int $attempt, mixed $exception): int => max(0, (int) config('eix-pricing.http.retry_delay_ms')) * $attempt,
            )
            ->sink($localPath)
            ->get((string) config('eix-pricing.download_url'), [
                'key' => $source->path,
                'attachmentFilename' => basename($source->path),
            ])
            ->throw();
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
