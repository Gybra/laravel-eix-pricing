<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;

final readonly class QuotePruner
{
    public function __construct(private DatabaseManager $database) {}

    public function prune(): int
    {
        if (Date::now()->isWeekend()) {
            return 0;
        }

        $cutoff = Date::now()->subDays(max(1, (int) config('eix-pricing.prune.retention_days')));
        $connection = $this->connection();

        return $connection->transaction(
            fn (): int => $connection->table('eix_quotes')
                ->where('imported_at', '<=', $cutoff)
                ->delete(),
        );
    }

    private function connection(): Connection
    {
        return $this->database->connection(config('eix-pricing.database.connection'));
    }
}
