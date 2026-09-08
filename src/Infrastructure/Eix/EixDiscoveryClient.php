<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Gybra\EixPricing\Domain\SourceFile;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Date;
use UnexpectedValueException;

final readonly class EixDiscoveryClient
{
    public function __construct(private Factory $http) {}

    /**
     * @return list<SourceFile>
     */
    public function recent(): array
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

        $cutoff = Date::now()->getTimestampMs()
            - max(0, (int) config('eix-pricing.import.lookback_minutes')) * 60_000;
        $sources = [];

        foreach ($entries as $entry) {
            $source = $this->mapEntry($entry);

            if ($source->timestampMilliseconds >= $cutoff) {
                $sources[] = $source;
            }
        }

        usort(
            $sources,
            fn (SourceFile $left, SourceFile $right): int => $left->timestampMilliseconds <=> $right->timestampMilliseconds
                ?: $left->path <=> $right->path,
        );

        return $sources;
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
}
