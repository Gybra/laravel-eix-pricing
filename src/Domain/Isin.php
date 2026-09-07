<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Domain;

use InvalidArgumentException;

final readonly class Isin
{
    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $value = strtoupper(trim($value));

        if (! self::hasValidFormat($value) || ! self::hasValidChecksum($value)) {
            throw new InvalidArgumentException('Invalid ISIN.');
        }

        return new self($value);
    }

    private static function hasValidFormat(string $value): bool
    {
        return preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $value) === 1;
    }

    private static function hasValidChecksum(string $value): bool
    {
        $expanded = '';

        foreach (str_split($value) as $character) {
            $expanded .= ctype_digit($character) ? $character : (string) (ord($character) - 55);
        }

        $sum = 0;

        foreach (array_reverse(str_split($expanded)) as $position => $digit) {
            $number = (int) $digit * ($position % 2 === 0 ? 1 : 2);
            $sum += intdiv($number, 10) + ($number % 10);
        }

        return $sum % 10 === 0;
    }
}
