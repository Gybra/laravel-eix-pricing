<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence\Models;

use Gybra\EixPricing\Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source
 * @property string $status
 * @property int $rows_imported
 */
#[UseFactory(ImportFactory::class)]
final class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $table = 'eix_imports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'status',
        'rows_imported',
        'started_at',
        'finished_at',
        'failure',
    ];

    public function getConnectionName(): ?string
    {
        $connection = config('eix-pricing.database.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows_imported' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
