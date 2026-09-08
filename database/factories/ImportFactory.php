<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Database\Factories;

use Gybra\EixPricing\Infrastructure\Persistence\Models\Import;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
final class ImportFactory extends Factory
{
    protected $model = Import::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = '2026-09-07 20:41:00.000';

        return [
            'source' => 'pretrade/2026-09-07/Pretrade.'.fake()->unique()->numerify('#############').'.csv.gz',
            'status' => 'completed',
            'rows_imported' => 1,
            'started_at' => $now,
            'finished_at' => $now,
            'failure' => null,
        ];
    }
}
