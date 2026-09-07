<?php

declare(strict_types=1);

use Gybra\EixPricing\Infrastructure\Http\QuoteController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    ...(array) config('eix-pricing.routes.middleware'),
    'throttle:'.config('eix-pricing.routes.throttle'),
])
    ->prefix((string) config('eix-pricing.routes.prefix'))
    ->group(function (): void {
        Route::get('/quotes/{isin}', QuoteController::class);
    });
