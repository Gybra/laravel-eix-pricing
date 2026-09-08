<?php

declare(strict_types=1);

use DateTimeImmutable;
use Gybra\EixPricing\Application\Contracts\QuoteServiceInterface;
use Gybra\EixPricing\Domain\QuoteRecord;
use Gybra\EixPricing\Infrastructure\Persistence\Models\Import;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::connection('package_testing')->dropAllTables();
    $this->artisan('migrate:fresh')->assertSuccessful();
    config()->set('eix-pricing.import.batch_size', 2);
});

function createImport(string $source): int
{
    return Import::factory()->create([
        'source' => $source,
        'status' => 'running',
        'rows_imported' => 0,
    ])->id;
}

function quoteRecord(
    string $isin,
    string $timestamp,
    int $sourceRow,
    string $bid,
    string $ask,
    string $price,
): QuoteRecord {
    return new QuoteRecord(
        $sourceRow,
        $isin,
        $bid,
        $ask,
        $price,
        'TRAD',
        new DateTimeImmutable($timestamp),
    );
}

it('keeps the newest quote across unordered batches and deterministic ties', function (): void {
    $importId = createImport('pretrade/latest.csv.gz');
    $records = [
        quoteRecord('IE000EOFR2K5', '2026-09-07T20:40:00.000Z', 2, '4.000000', '6.000000', '5.0000000'),
        quoteRecord('LU1094612022', '2026-09-07T20:39:00.000Z', 3, '19.000000', '21.000000', '20.0000000'),
        quoteRecord('IE000EOFR2K5', '2026-09-07T20:38:00.000Z', 4, '3.000000', '5.000000', '4.0000000'),
        quoteRecord('IE000EOFR2K5', '2026-09-07T20:40:00.000Z', 5, '4.500000', '6.500000', '5.5000000'),
    ];

    $batchBindingCounts = [];
    DB::connection('package_testing')->listen(
        function (QueryExecuted $query) use (&$batchBindingCounts): void {
            if (str_contains($query->sql, 'INSERT INTO') && str_contains($query->sql, 'eix_quotes')) {
                $batchBindingCounts[] = count($query->bindings);
            }
        },
    );

    $written = app(QuoteServiceInterface::class)->write($records, $importId, 1788759600000);

    $quotes = DB::connection('package_testing')
        ->table('eix_quotes')
        ->orderBy('isin')
        ->get();

    expect($written)->toBe(4)
        ->and($quotes)->toHaveCount(2)
        ->and($batchBindingCounts)->toBe([18, 9])
        ->and($quotes->first()->isin)->toBe('IE000EOFR2K5')
        ->and((float) $quotes->first()->bid)->toBe(4.5)
        ->and($quotes->first()->source_row)->toBe(5)
        ->and(Schema::connection('testing')->hasTable('eix_quotes'))->toBeFalse();
});

it('uses source timestamp before row order when quote timestamps tie', function (): void {
    $quotedAt = '2026-09-07T20:40:00.000Z';
    $firstImport = createImport('pretrade/first.csv.gz');
    $newerImport = createImport('pretrade/newer.csv.gz');
    $olderImport = createImport('pretrade/older.csv.gz');

    app(QuoteServiceInterface::class)->write([
        quoteRecord('IE000EOFR2K5', $quotedAt, 100, '4.000000', '6.000000', '5.0000000'),
    ], $firstImport, 2000);
    app(QuoteServiceInterface::class)->write([
        quoteRecord('IE000EOFR2K5', $quotedAt, 1, '5.000000', '7.000000', '6.0000000'),
    ], $newerImport, 3000);
    app(QuoteServiceInterface::class)->write([
        quoteRecord('IE000EOFR2K5', $quotedAt, 999, '3.000000', '5.000000', '4.0000000'),
    ], $olderImport, 1000);

    $quote = DB::connection('package_testing')->table('eix_quotes')->first();

    expect($quote->import_id)->toBe($newerImport)
        ->and((float) $quote->bid)->toBe(5.0)
        ->and($quote->source_timestamp)->toBe(3000)
        ->and($quote->source_row)->toBe(1);
});

it('rolls back every batch when source iteration fails', function (): void {
    config()->set('eix-pricing.import.batch_size', 1);
    $importId = createImport('pretrade/failing.csv.gz');
    $records = (function (): Generator {
        yield quoteRecord('IE000EOFR2K5', '2026-09-07T20:40:00.000Z', 2, '4.000000', '6.000000', '5.0000000');

        throw new RuntimeException('Source failed.');
    })();

    expect(fn () => app(QuoteServiceInterface::class)->write($records, $importId, 1788759600000))
        ->toThrow(RuntimeException::class, 'Source failed.');

    expect(DB::connection('package_testing')->table('eix_quotes')->count())->toBe(0);
});
