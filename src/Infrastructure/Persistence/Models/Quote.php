<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence\Models;

use Gybra\EixPricing\Database\Factories\QuoteFactory;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Traits\UsesPackageConnection;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $import_id
 * @property string $isin
 * @property string $bid
 * @property string $ask
 * @property string $price
 * @property string $status
 */
#[UseFactory(QuoteFactory::class)]
final class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    use UsesPackageConnection;

    public $timestamps = false;

    protected $table = 'eix_quotes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_id',
        'isin',
        'bid',
        'ask',
        'price',
        'status',
        'quoted_at',
        'source_timestamp',
        'source_row',
        'imported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bid' => 'decimal:6',
            'ask' => 'decimal:6',
            'price' => 'decimal:7',
            'quoted_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'source_timestamp' => 'integer',
            'source_row' => 'integer',
            'import_id' => 'integer',
        ];
    }
}
