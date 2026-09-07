<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Http;

use Closure;
use Gybra\EixPricing\Domain\Isin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'isin' => [
                'required',
                'string',
                'max:32',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        Isin::from((string) $value);
                    } catch (InvalidArgumentException) {
                        $fail('The ISIN is invalid.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'isin' => Str::of((string) $this->route('isin'))->trim()->upper()->toString(),
        ]);
    }
}
