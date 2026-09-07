<?php

declare(strict_types=1);

use Gybra\EixPricing\EixPricingServiceProvider;

it('registers the package service provider', function (): void {
    expect(app()->getProvider(EixPricingServiceProvider::class))
        ->toBeInstanceOf(EixPricingServiceProvider::class);
});
