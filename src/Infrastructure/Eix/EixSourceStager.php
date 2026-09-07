<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Closure;
use Gybra\EixPricing\Domain\SourceFile;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory;
use RuntimeException;

final readonly class EixSourceStager
{
    public function __construct(
        private Factory $http,
        private FilesystemManager $filesystems,
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

        $disk = $this->filesystems->disk((string) config('eix-pricing.storage.disk'));
        $storagePath = $this->storagePath($source);

        try {
            $this->download($source, $localPath);
            $stream = fopen($localPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the downloaded EIX source file.');
            }

            try {
                if (! $disk->put($storagePath, $stream)) {
                    throw new RuntimeException('Unable to stage the EIX source file.');
                }
            } finally {
                fclose($stream);
            }

            return $process($localPath);
        } finally {
            try {
                $disk->delete($storagePath);
            } finally {
                if (is_file($localPath)) {
                    unlink($localPath);
                }
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
                (int) config('eix-pricing.http.retry_delay_ms'),
            )
            ->sink($localPath)
            ->get((string) config('eix-pricing.download_url'), [
                'key' => $source->path,
                'attachmentFilename' => basename($source->path),
            ])
            ->throw();
    }

    private function storagePath(SourceFile $source): string
    {
        $prefix = trim((string) config('eix-pricing.storage.prefix'), '/');

        return ($prefix === '' ? '' : $prefix.'/').basename($source->path);
    }
}
