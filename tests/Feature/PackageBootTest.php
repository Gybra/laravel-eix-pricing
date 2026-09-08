<?php

declare(strict_types=1);

use Gybra\EixPricing\Application\Contracts\ImportServiceInterface;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\EixPricingServiceProvider;
use Gybra\EixPricing\Infrastructure\Persistence\ImportService;
use Gybra\EixPricing\Infrastructure\Persistence\QuoteService;

it('registers the package service provider', function (): void {
    expect(app()->getProvider(EixPricingServiceProvider::class))
        ->toBeInstanceOf(EixPricingServiceProvider::class);
});

it('binds persistence service interfaces', function (): void {
    expect(app(QuoteServiceInterface::class))->toBeInstanceOf(QuoteService::class)
        ->and(app(ImportServiceInterface::class))->toBeInstanceOf(ImportService::class);
});
