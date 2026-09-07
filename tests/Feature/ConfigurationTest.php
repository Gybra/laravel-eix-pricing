<?php

declare(strict_types=1);

use Gybra\EixPricing\EixPricingServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges package configuration defaults', function (): void {
    expect(config('eix-pricing.discovery_url'))
        ->toBe('https://european-investor-exchange.com/api/trade-files?tradeFileType=pretrade')
        ->and(config('eix-pricing.import.batch_size'))->toBe(1000)
        ->and(config('eix-pricing.schedule.cron'))->toBe('*/15 * * * *')
        ->and(config('eix-pricing.routes.prefix'))->toBe('api');
});

it('publishes package configuration', function (): void {
    expect(ServiceProvider::pathsToPublish(
        EixPricingServiceProvider::class,
        'eix-pricing-config',
    ))->toBe([
        dirname(__DIR__, 2).'/config/eix-pricing.php' => config_path('eix-pricing.php'),
    ]);
});
