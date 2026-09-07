<?php

declare(strict_types=1);

namespace Gybra\EixPricing;

use Gybra\EixPricing\Infrastructure\Console\ImportEixCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class EixPricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/eix-pricing.php', 'eix-pricing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');

        $this->publishes([
            dirname(__DIR__).'/config/eix-pricing.php' => config_path('eix-pricing.php'),
        ], 'eix-pricing-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportEixCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('eix-pricing.schedule.enabled')) {
                return;
            }

            $event = $schedule
                ->command('eix:import')
                ->cron((string) config('eix-pricing.schedule.cron'))
                ->withoutOverlapping((int) config('eix-pricing.schedule.overlap_minutes'));

            if (config('eix-pricing.schedule.on_one_server')) {
                $event->onOneServer();
            }
        });
    }
}
