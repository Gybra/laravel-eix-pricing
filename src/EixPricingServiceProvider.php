<?php

declare(strict_types=1);

namespace Gybra\EixPricing;

use Illuminate\Support\ServiceProvider;

final class EixPricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/eix-pricing.php', 'eix-pricing');
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__).'/config/eix-pricing.php' => config_path('eix-pricing.php'),
        ], 'eix-pricing-config');
    }
}
