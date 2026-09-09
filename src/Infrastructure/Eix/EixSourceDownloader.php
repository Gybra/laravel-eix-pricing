<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Closure;
use Gybra\EixPricing\Domain\SourceFile;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final readonly class EixSourceDownloader
{
    public function __construct(private Factory $http) {}

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
            $this->download($source, $localPath);

            Log::info('EIX source downloaded', [
                'source' => $source->path,
                'compressed_bytes' => filesize($localPath),
                'download_duration_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

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
