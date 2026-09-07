<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Gybra\EixPricing\Domain\SourceFile;
use Illuminate\Http\Client\Factory;
use UnexpectedValueException;

final readonly class EixDiscoveryClient
{
    public function __construct(private Factory $http) {}

    public function newest(): SourceFile
    {
        $entries = $this->http
            ->acceptJson()
            ->connectTimeout((int) config('eix-pricing.http.connect_timeout'))
            ->timeout((int) config('eix-pricing.http.timeout'))
            ->retry(
                (int) config('eix-pricing.http.retries') + 1,
                (int) config('eix-pricing.http.retry_delay_ms'),
            )
            ->get((string) config('eix-pricing.discovery_url'))
            ->throw()
            ->json();

        if (! is_array($entries) || $entries === []) {
            throw new UnexpectedValueException('EIX discovery returned no source files.');
        }

        $newest = null;

        foreach ($entries as $entry) {
            $source = $this->mapEntry($entry);

            if ($newest === null || $this->isNewer($source, $newest)) {
                $newest = $source;
            }
        }

        return $newest;
    }

    private function mapEntry(mixed $entry): SourceFile
    {
        $path = is_array($entry) ? ($entry['fileName'] ?? null) : null;

        if (! is_string($path) || preg_match(
            '/^pretrade\/\d{4}-\d{2}-\d{2}\/Pretrade\.(\d+)\.csv\.gz$/',
            $path,
            $matches,
        ) !== 1) {
            throw new UnexpectedValueException('Malformed EIX discovery entry.');
        }

        return new SourceFile($path, (int) $matches[1]);
    }

    private function isNewer(SourceFile $candidate, SourceFile $current): bool
    {
        return $candidate->timestampMilliseconds > $current->timestampMilliseconds
            || ($candidate->timestampMilliseconds === $current->timestampMilliseconds
                && $candidate->path > $current->path);
    }
}
