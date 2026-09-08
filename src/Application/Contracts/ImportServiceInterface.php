<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application\Contracts;

use Closure;
use Gybra\EixPricing\Application\ImportState;

interface ImportServiceInterface
{
    public function findBySource(string $source): ?ImportState;

    public function start(string $source, ?int $existingId): int;

    public function complete(int $id, int $rowsImported): void;

    public function fail(int $id, string $failure): void;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Closure $callback): mixed;
}
