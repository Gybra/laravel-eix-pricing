<?php

declare(strict_types=1);

namespace Gybra\EixPricing;

use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Infrastructure\Console\ImportEixCommand;
use Gybra\EixPricing\Infrastructure\Console\PruneQuotesCommand;
use Gybra\EixPricing\Infrastructure\Persistence\ImportService;
use Gybra\EixPricing\Infrastructure\Persistence\QuoteService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class EixPricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/eix-pricing.php', 'eix-pricing');
        $this->app->bind(QuoteServiceInterface::class, QuoteService::class);
        $this->app->bind(ImportServiceInterface::class, ImportService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');

        if (config('eix-pricing.routes.enabled')) {
            $this->loadRoutesFrom(dirname(__DIR__).'/routes/api.php');
        }

        $this->publishes([
            dirname(__DIR__).'/config/eix-pricing.php' => config_path('eix-pricing.php'),
        ], 'eix-pricing-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportEixCommand::class,
                PruneQuotesCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('eix-pricing.schedule.enabled')) {
                return;
            }

            $this->scheduleCommand(
                $schedule,
                'eix:import',
                (string) config('eix-pricing.schedule.cron'),
                (int) config('eix-pricing.schedule.overlap_minutes'),
            );
            $this->scheduleCommand(
                $schedule,
                'eix:prune-quotes',
                (string) config('eix-pricing.prune.cron'),
            );
        });
    }

    private function scheduleCommand(
        Schedule $schedule,
        string $command,
        string $cron,
        ?int $overlapMinutes = null,
    ): void {
        $event = $schedule->command($command)->cron($cron)->runInBackground();

        $event = $overlapMinutes === null
            ? $event->withoutOverlapping()
            : $event->withoutOverlapping($overlapMinutes);

        if (config('eix-pricing.schedule.on_one_server')) {
            $event->onOneServer();
        }
    }
}
