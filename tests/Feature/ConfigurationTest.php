<?php

declare(strict_types=1);

use Gybra\EixPricing\EixPricingServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges package configuration defaults', function (): void {
    expect(config('eix-pricing.discovery_url'))
        ->toBe('https://european-investor-exchange.com/api/trade-files?tradeFileType=pretrade')
        ->and(config('eix-pricing.import.batch_size'))->toBe(2500)
        ->and(array_key_exists('storage', config('eix-pricing')))->toBeFalse()
        ->and(config('eix-pricing.import.lock_name'))->toBe('eix-pricing:import')
        ->and(config('eix-pricing.import.lock_seconds'))->toBe(10800)
        ->and(config('eix-pricing.import.lookback_minutes'))->toBe(30)
        ->and(config('eix-pricing.import.isins'))->toBeNull()
        ->and(config('eix-pricing.schedule.cron'))->toBe('*/30 * * * 1-5')
        ->and(config('eix-pricing.prune.cron'))->toBe('0 1 * * 1-5')
        ->and(config('eix-pricing.prune.retention_days'))->toBe(2)
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
