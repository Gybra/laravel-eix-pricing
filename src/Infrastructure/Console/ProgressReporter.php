<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Console;

use Closure;

final class ProgressReporter
{
    /** @var Closure(string): void|null */
    private $sink = null;

    public function bind(?Closure $sink): void
    {
        $this->sink = $sink;
    }

    public function say(string $message): void
    {
        if ($this->sink === null) {
            return;
        }

        ($this->sink)($message);
    }

    public function duration(float $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return round($milliseconds).' ms';
        }

        return number_format($milliseconds / 1000, 1).' s';
    }
}
