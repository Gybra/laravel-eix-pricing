<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Http;

use Carbon\CarbonImmutable;
use Gybra\EixPricing\Domain\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class QuoteResource extends JsonResource
{
    /**
     * @return array{isin: string, price: float, bid: float, ask: float, quoted_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $quote = $this->quote();

        return [
            'isin' => $quote->isin,
            'price' => (float) $quote->price,
            'bid' => (float) $quote->bid,
            'ask' => (float) $quote->ask,
            'quoted_at' => CarbonImmutable::instance($quote->quotedAt)->toISOString(),
        ];
    }

    private function quote(): Quote
    {
        if (! $this->resource instanceof Quote) {
            throw new LogicException('QuoteResource requires a Quote.');
        }

        return $this->resource;
    }
}
