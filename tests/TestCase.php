<?php

declare(strict_types=1);

namespace Tests;

use Gybra\EixPricing\EixPricingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EixPricingServiceProvider::class];
    }
}
