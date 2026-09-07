<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Http;

use Gybra\EixPricing\Application\QuoteLookup;
use Gybra\EixPricing\Application\QuoteNotFound;

final class QuoteController
{
    public function __invoke(QuoteRequest $request, QuoteLookup $lookup): QuoteResource
    {
        try {
            return new QuoteResource($lookup->find((string) $request->validated('isin')));
        } catch (QuoteNotFound) {
            abort(404);
        }
    }
}
