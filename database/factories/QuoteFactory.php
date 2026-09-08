<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Database\Factories;

use Gybra\EixPricing\Infrastructure\Persistence\Models\Import;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quote>
 */
final class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = '2026-09-07 20:41:00.000';

        return [
            'import_id' => Import::factory(),
            'isin' => 'IE000EOFR2K5',
            'bid' => '4.461500',
            'ask' => '4.623500',
            'price' => '4.5425000',
            'status' => 'TRAD',
            'quoted_at' => $now,
            'source_timestamp' => 1_788_759_600_000,
            'source_row' => 1,
            'imported_at' => $now,
        ];
    }
}
