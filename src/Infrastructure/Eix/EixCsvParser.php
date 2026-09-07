<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Gybra\EixPricing\Domain\QuoteRecord;
use RuntimeException;
use UnexpectedValueException;

final class EixCsvParser
{
    private const array HEADERS = [
        'Trading day & Trading time UTC',
        'Instrument Identifier',
        'Bid Quantity',
        'Ask Quantity',
        'Bid Price',
        'Ask Price',
        'Price Currency',
        'Price Notation',
        'Status',
    ];

    /**
     * @return Generator<int, QuoteRecord>
     */
    public function records(string $path): Generator
    {
        $handle = fopen('compress.zlib://'.$path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the EIX gzip source.');
        }

        try {
            if (fgetcsv($handle, null, ',', '"', '') !== self::HEADERS) {
                throw new UnexpectedValueException('Malformed EIX CSV header.');
            }

            $sourceRow = 1;

            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $sourceRow++;

                if ($values === [null]) {
                    continue;
                }

                yield $this->normalize($values, $sourceRow);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function normalize(array $values, int $sourceRow): QuoteRecord
    {
        if (count($values) !== count(self::HEADERS) || in_array(null, $values, true)) {
            $this->malformed($sourceRow, 'column count');
        }

        /** @var array<int, string> $values */
        [$timestamp, $isin, $bidQuantity, $askQuantity, $bid, $ask, $currency, $notation, $status] = $values;

        $this->decimal($bidQuantity, $sourceRow, 'bid quantity');
        $this->decimal($askQuantity, $sourceRow, 'ask quantity');
        $bidDecimal = $this->decimal($bid, $sourceRow, 'bid');
        $askDecimal = $this->decimal($ask, $sourceRow, 'ask');

        if (! $this->validIsin($isin)) {
            $this->malformed($sourceRow, 'ISIN');
        }

        if ($bidDecimal->isGreaterThan($askDecimal)) {
            $this->malformed($sourceRow, 'bid exceeds ask');
        }

        if ($currency !== 'EUR' || $notation !== 'MONE' || preg_match('/^[A-Z]{4}$/', $status) !== 1) {
            $this->malformed($sourceRow, 'quote metadata');
        }

        $quotedAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        if ($quotedAt === false || $quotedAt->format('Y-m-d\TH:i:s.v\Z') !== $timestamp) {
            $this->malformed($sourceRow, 'timestamp');
        }

        return new QuoteRecord(
            $sourceRow,
            $isin,
            $bid,
            $ask,
            (string) $bidDecimal->plus($askDecimal)->dividedBy(2, 7),
            $status,
            $quotedAt,
        );
    }

    private function decimal(string $value, int $sourceRow, string $field): BigDecimal
    {
        if (preg_match('/^\d+(?:\.\d{1,6})?$/', $value) !== 1) {
            $this->malformed($sourceRow, $field);
        }

        return BigDecimal::of($value);
    }

    private function validIsin(string $isin): bool
    {
        if (preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin) !== 1) {
            return false;
        }

        $expanded = '';

        foreach (str_split($isin) as $character) {
            $expanded .= ctype_digit($character) ? $character : (string) (ord($character) - 55);
        }

        $sum = 0;

        foreach (array_reverse(str_split($expanded)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 === 0 ? 1 : 2);
            $sum += intdiv($value, 10) + ($value % 10);
        }

        return $sum % 10 === 0;
    }

    private function malformed(int $sourceRow, string $reason): never
    {
        throw new UnexpectedValueException("Malformed EIX CSV row {$sourceRow}: {$reason}.");
    }
}
