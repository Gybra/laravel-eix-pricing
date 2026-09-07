<?php

declare(strict_types=1);

use Gybra\EixPricing\Infrastructure\Eix\EixCsvParser;

function temporaryGzip(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'eix-test-');

    if ($path === false || file_put_contents($path, gzencode($contents)) === false) {
        throw new RuntimeException('Unable to create test gzip.');
    }

    return $path;
}

it('streams and normalizes the verified pre-trade fixture', function (): void {
    $records = app(EixCsvParser::class)->records(
        __DIR__.'/../Fixtures/eix/pretrade.csv.gz',
    );

    expect($records)->toBeInstanceOf(Generator::class);

    $first = $records->current();

    expect($first->sourceRow)->toBe(2)
        ->and($first->isin)->toBe('IE000EOFR2K5')
        ->and($first->bid)->toBe('4.461500')
        ->and($first->ask)->toBe('4.623500')
        ->and($first->price)->toBe('4.5425000')
        ->and($first->status)->toBe('TRAD')
        ->and($first->quotedAt->format('Y-m-d\TH:i:s.v\Z'))
        ->toBe('2026-09-07T20:36:01.000Z');
});

it('parses quoted CSV fields', function (): void {
    $path = temporaryGzip(implode("\n", [
        'Trading day & Trading time UTC,Instrument Identifier,Bid Quantity,Ask Quantity,Bid Price,Ask Price,Price Currency,Price Notation,Status',
        '"2026-09-07T20:36:01.000Z","IE000EOFR2K5","1200.000000","1200.000000","4.461500","4.623500","EUR","MONE","TRAD"',
        '',
    ]));

    try {
        expect(app(EixCsvParser::class)->records($path)->current()->isin)
            ->toBe('IE000EOFR2K5');
    } finally {
        unlink($path);
    }
});

it('rejects malformed headers', function (): void {
    $path = temporaryGzip("wrong,header\n");

    try {
        expect(fn () => app(EixCsvParser::class)->records($path)->current())
            ->toThrow(UnexpectedValueException::class, 'header');
    } finally {
        unlink($path);
    }
});

it('reports the physical row for malformed records', function (): void {
    $path = temporaryGzip(implode("\n", [
        'Trading day & Trading time UTC,Instrument Identifier,Bid Quantity,Ask Quantity,Bid Price,Ask Price,Price Currency,Price Notation,Status',
        '2026-09-07T20:36:01.000Z,INVALID,1200,1200,4.461500,4.623500,EUR,MONE,TRAD',
        '',
    ]));

    try {
        expect(fn () => app(EixCsvParser::class)->records($path)->current())
            ->toThrow(UnexpectedValueException::class, 'row 2');
    } finally {
        unlink($path);
    }
});

it('rejects invalid quote values', function (string $record, string $reason): void {
    $path = temporaryGzip(implode("\n", [
        'Trading day & Trading time UTC,Instrument Identifier,Bid Quantity,Ask Quantity,Bid Price,Ask Price,Price Currency,Price Notation,Status',
        $record,
        '',
    ]));

    try {
        expect(fn () => app(EixCsvParser::class)->records($path)->current())
            ->toThrow(UnexpectedValueException::class, $reason);
    } finally {
        unlink($path);
    }
})->with([
    'decimal scale' => [
        '2026-09-07T20:36:01.000Z,IE000EOFR2K5,1200,1200,4.1234567,4.623500,EUR,MONE,TRAD',
        'bid',
    ],
    'timestamp' => [
        '2026-09-07 20:36:01,IE000EOFR2K5,1200,1200,4.461500,4.623500,EUR,MONE,TRAD',
        'timestamp',
    ],
    'metadata' => [
        '2026-09-07T20:36:01.000Z,IE000EOFR2K5,1200,1200,4.461500,4.623500,USD,MONE,TRAD',
        'quote metadata',
    ],
    'crossed book' => [
        '2026-09-07T20:36:01.000Z,IE000EOFR2K5,1200,1200,5.000000,4.623500,EUR,MONE,TRAD',
        'bid exceeds ask',
    ],
]);

it('iterates generated multi-batch data', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'eix-test-');
    $handle = $path === false ? false : gzopen($path, 'wb9');

    if ($path === false || $handle === false) {
        throw new RuntimeException('Unable to create test gzip.');
    }

    gzwrite($handle, "Trading day & Trading time UTC,Instrument Identifier,Bid Quantity,Ask Quantity,Bid Price,Ask Price,Price Currency,Price Notation,Status\n");

    for ($row = 0; $row < 2001; $row++) {
        gzwrite($handle, "2026-09-07T20:36:01.000Z,IE000EOFR2K5,1200,1200,4.461500,4.623500,EUR,MONE,TRAD\n");
    }

    gzclose($handle);

    try {
        $count = 0;

        foreach (app(EixCsvParser::class)->records($path) as $record) {
            $count++;
        }

        expect($count)->toBe(2001);
    } finally {
        unlink($path);
    }
});
