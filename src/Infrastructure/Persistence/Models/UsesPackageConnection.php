<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Persistence\Models;

trait UsesPackageConnection
{
    public function getConnectionName(): ?string
    {
        $connection = config('eix-pricing.database.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }
}
