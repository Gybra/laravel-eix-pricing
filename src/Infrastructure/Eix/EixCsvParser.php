<?php

declare(strict_types=1);

namespace Gybra\EixPricing\Infrastructure\Eix;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Gybra\EixPricing\Domain\Isin;
use Gybra\EixPricing\Domain\QuoteRecord;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

final readonly class EixCsvParser
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
        $this->assertColumnCount($values, $sourceRow);

        /** @var array<int, string> $values */
        [$timestamp, $isin, $bidQuantity, $askQuantity, $bid, $ask, $currency, $notation, $status] = $values;

        $this->decimal($bidQuantity, $sourceRow, 'bid quantity');
        $this->decimal($askQuantity, $sourceRow, 'ask quantity');
        $bidDecimal = $this->decimal($bid, $sourceRow, 'bid');
        $askDecimal = $this->decimal($ask, $sourceRow, 'ask');

        try {
            $isin = Isin::from($isin)->value;
        } catch (InvalidArgumentException) {
            $this->malformed($sourceRow, 'ISIN');
        }

        $this->assertMetadata($currency, $notation, $status, $sourceRow);

        return new QuoteRecord(
            $sourceRow,
            $isin,
            $bid,
            $ask,
            (string) $bidDecimal->plus($askDecimal)->dividedBy(2, 7),
            $status,
            $this->parseQuotedAt($timestamp, $sourceRow),
        );
    }

    /**
     * @param  array<int, string|null>  $values
     */
    private function assertColumnCount(array $values, int $sourceRow): void
    {
        if (count($values) !== count(self::HEADERS) || in_array(null, $values, true)) {
            $this->malformed($sourceRow, 'column count');
        }
    }

    private function assertMetadata(string $currency, string $notation, string $status, int $sourceRow): void
    {
        if ($currency !== 'EUR' || $notation !== 'MONE' || preg_match('/^[A-Z]{4}$/', $status) !== 1) {
            $this->malformed($sourceRow, 'quote metadata');
        }
    }

    private function parseQuotedAt(string $timestamp, int $sourceRow): DateTimeImmutable
    {
        $quotedAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        if ($quotedAt === false || $quotedAt->format('Y-m-d\TH:i:s.v\Z') !== $timestamp) {
            $this->malformed($sourceRow, 'timestamp');
        }

        return $quotedAt;
    }

    private function decimal(string $value, int $sourceRow, string $field): BigDecimal
    {
        if (preg_match('/^\d+(?:\.\d{1,6})?$/', $value) !== 1) {
            $this->malformed($sourceRow, $field);
        }

        return BigDecimal::of($value);
    }

    private function malformed(int $sourceRow, string $reason): never
    {
        throw new UnexpectedValueException("Malformed EIX CSV row {$sourceRow}: {$reason}.");
    }
}
