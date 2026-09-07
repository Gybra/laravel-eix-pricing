<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Application;

use RuntimeException;

final class ImportAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An EIX import is already running.');
    }
}
