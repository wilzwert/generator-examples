<?php

use App\Etl\Batcher;
use App\Etl\InvalidRowException;
use App\Etl\Order;
use App\Etl\Reader;
use App\Etl\Transformer;
use App\Etl\Writer;
use App\Pipeline\Channel;
use App\Pipeline\Command\Close;
use App\Pipeline\Command\Receive;
use App\Pipeline\Command\Send;
use App\Pipeline\DeadlockException;
use App\Pipeline\Scenario;
use App\Pipeline\Scheduler;
use App\Support\Paths;

$expectedTotalRows = (int) ($argv[1] ?? 1000);

Scenario::scenarioShouldThrow(
    'Reader sends rows',
    '3 rows received, then deadlock: reader waiting to send on lines',
    DeadlockException::class,
    static function () {
        $channel = new Channel(3, 'lines');
        $reader = new Reader(Paths::ORDERS_FILE_PATH, $channel);
        $scheduler = new Scheduler();
        $scheduler->spawn('reader', $reader());
        $scheduler->spawn('transformer', Scenario::scripted('transformer', [new Receive($channel), new Receive($channel), new Receive($channel)]));

        $scheduler->run();
    }
);

Scenario::scenario('Consumer reads all rows', sprintf('Consumer should receive %d values', $expectedTotalRows), static function () use ($expectedTotalRows) {
    $channel = new Channel(5, 'lines');
    $reader = new Reader(Paths::ORDERS_FILE_PATH, $channel);
    $scheduler = new Scheduler();
    $scheduler->spawn('reader', $reader());
    $rows = [];
    $scheduler->spawn('sink', Scenario::drain('sink', $channel, $rows));
    $scheduler->run();

    if ($expectedTotalRows !== count($rows)) {
        throw new AssertionError(sprintf('expected %d rows, got %d', $expectedTotalRows, count($rows)));
    }
});

Scenario::scenarioShouldThrow('Empty file passed to reader', 'Should throw runtimeexception', RuntimeException::class, static function () {
    $channel = new Channel(5, 'lines');

    $tmp = tempnam(__DIR__.'/../var', '');
    try {
        $reader = new Reader($tmp, $channel);
        $scheduler = new Scheduler();
        $scheduler->spawn('reader', $reader());
        $scheduler->run();
    } finally {
        if (false !== $tmp) {
            unlink($tmp);
        }
    }
});

Scenario::scenarioShouldThrow('File has wrong headers', 'Should throw runtimeexception', RuntimeException::class, static function () {
    $channel = new Channel(5, 'lines');

    $tmp = tempnam(__DIR__.'/../var', '');
    try {
        file_put_contents(
            $tmp,
            <<<CSV
            id,wrong
            CSV
        );
        $reader = new Reader($tmp, $channel);
        $scheduler = new Scheduler();
        $scheduler->spawn('reader', $reader());
        $scheduler->run();
    } finally {
        if (false !== $tmp) {
            unlink($tmp);
        }
    }
});

Scenario::scenarioShouldThrow(
    'Zero amount',
    'should throw InvalidRowException',
    InvalidRowException::class,
    static function () {
        $transformer = new Transformer(new Channel(1, 'in'), new Channel(1, 'out'), new Channel(1, 'rejects'));
        $transformer->parseRow(['1', 'Customer1', '0', '2026-10-16 11:00:00']);
    }
);

Scenario::scenarioShouldThrow(
    'Impossible date',
    'should throw InvalidRowException',
    InvalidRowException::class,
    static function () {
        $transformer = new Transformer(new Channel(1, 'in'), new Channel(1, 'out'), new Channel(1, 'rejects'));
        $transformer->parseRow(['1', 'Customer1', '1', '2026-02-30 11:00:00']);
    }
);

Scenario::scenarioShouldThrow(
    'Empty customer',
    'should throw InvalidRowException',
    InvalidRowException::class,
    static function () {
        $transformer = new Transformer(new Channel(1, 'in'), new Channel(1, 'out'), new Channel(1, 'rejects'));
        $transformer->parseRow(['1', '   ', '1', '2026-02-12 11:00:00']);
    }
);

Scenario::scenario(
    'Parse order',
    'should return order',
    static function () {
        $transformer = new Transformer(new Channel(1, 'in'), new Channel(1, 'out'), new Channel(1, 'rejects'));
        $order = $transformer->parseRow(['1', 'Customer1', '1', '2026-02-12 11:00:00']);

        if (1 !== $order->id) {
            throw new AssertionError('Id should equal 1 (int)');
        }
        if ('Customer1' !== $order->customer) {
            throw new AssertionError("Customer should be 'Customer1'");
        }
        if (1.0 !== $order->amount) {
            throw new AssertionError('Amount should equal 1.0 (float)');
        }
        if ($order->createdAt != new DateTimeImmutable('2026-02-12 11:00:00')) {
            throw new AssertionError("Date should equal '2026-02-12 11:00:00'");
        }
    }
);

Scenario::scenario(
    'Transformer receives 3 rows, one invalid row',
    'should send 2 orders and 1 reject',
    static function () {
        $in = new Channel(3, 'read');
        $out = new Channel(2, 'orders');
        $rejects = new Channel(1, 'rejects');

        $transformer = new Transformer($in, $out, $rejects);
        $scheduler = new Scheduler();

        $scheduler->spawn('transformer', $transformer());
        $scheduler->spawn(
            'reader',
            Scenario::scripted(
                'reader', [
                    new Send($in, ['1', 'Customer1', '1', '2026-10-16 11:00:00']),
                    new Send($in, ['2', 'Customer2', '0', '2026-10-16 11:00:00']),
                    new Send($in, ['3', 'Customer3', '2', '2026-10-16 11:00:00']),
                    new Close($in),
                ]
            ))
        ;

        $receivedOrders = [];
        $scheduler->spawn(
            'orders',
            Scenario::drain('orders', $out, $receivedOrders)
        );

        $receivedRejects = [];
        $scheduler->spawn(
            'rejects',
            Scenario::drain('rejects', $rejects, $receivedRejects)
        );

        $scheduler->run();

        if (2 !== count($receivedOrders)) {
            throw new AssertionError('Should have received 2 orders');
        }
        if (1 !== count($receivedRejects)) {
            throw new AssertionError('Should have received 1 reject');
        }
    }
);

Scenario::scenario(
    'Transformer receives all rows',
    sprintf('orders + rejects should equal %d rows ', $expectedTotalRows),
    static function () use ($expectedTotalRows) {
        $read = new Channel(3, 'read');
        $orders = new Channel(2, 'orders');
        $rejects = new Channel(1, 'rejects');

        $transformer = new Transformer($read, $orders, $rejects);
        $scheduler = new Scheduler();

        $reader = new Reader(Paths::ORDERS_FILE_PATH, $read);

        $scheduler->spawn('transformer', $transformer());
        $scheduler->spawn('reader', $reader());

        $receivedOrders = [];
        $scheduler->spawn(
            'orders',
            Scenario::drain('orders', $orders, $receivedOrders)
        );

        $receivedRejects = [];
        $scheduler->spawn(
            'rejects',
            Scenario::drain('rejects', $rejects, $receivedRejects)
        );

        $scheduler->run();

        $totalRows = count($receivedOrders) + count($receivedRejects);

        if ($totalRows !== $expectedTotalRows) {
            throw new AssertionError(sprintf('Total rows (orders+rejects) should equal %d', $expectedTotalRows));
        }
    }
);

Scenario::scenario(
    'Batcher (size 2)',
    'reading 5 orders should create 3 batches (2, 2, 1)',
    static function () {
        $orders = new Channel(2, 'orders');
        $batches = new Channel(1, 'batches');

        $batcher = new Batcher(2, $orders, $batches);
        $scheduler = new Scheduler();
        $d = new DateTimeImmutable('2026-10-16 11:00:00');
        $scheduler->spawn('transformer', Scenario::scripted(
            'transformer', [
                new Send($orders, new Order(1, 'Customer1', 10.50, $d)),
                new Send($orders, new Order(2, 'Customer2', 43.50, $d)),
                new Send($orders, new Order(3, 'Customer3', 12.50, $d)),
                new Send($orders, new Order(4, 'Customer4', 34.74, $d)),
                new Send($orders, new Order(5, 'Customer4', 21.12, $d)),
                new Close($orders),
            ]
        ));
        $scheduler->spawn('batcher', $batcher());
        $receivedBatches = [];
        $scheduler->spawn('writer', Scenario::drain('writer', $batches, $receivedBatches));
        $scheduler->run();

        if (3 !== count($receivedBatches)) {
            throw new AssertionError('Writer should have received 3 batches');
        }

        $batchesSizes = array_map(
            static fn (mixed $batch): int => is_array($batch) ? count($batch) : throw new AssertionError('Batch should be an array'),
            $receivedBatches
        );

        if ([2, 2, 1] !== $batchesSizes) {
            throw new AssertionError('Batches should have sizes 2, 2, 1');
        }
    }
);

Scenario::scenario(
    'Batcher (size 2)',
    'reading 4 orders should create 2 batches (2, 2)',
    static function () {
        $orders = new Channel(2, 'orders');
        $batches = new Channel(1, 'batches');

        $batcher = new Batcher(2, $orders, $batches);
        $scheduler = new Scheduler();
        $d = new DateTimeImmutable('2026-10-16 11:00:00');
        $scheduler->spawn('transformer', Scenario::scripted(
            'transformer', [
                new Send($orders, new Order(1, 'Customer1', 10.50, $d)),
                new Send($orders, new Order(2, 'Customer2', 43.50, $d)),
                new Send($orders, new Order(3, 'Customer3', 12.50, $d)),
                new Send($orders, new Order(4, 'Customer4', 34.74, $d)),
                new Close($orders),
            ]
        ));
        $scheduler->spawn('batcher', $batcher());
        $receivedBatches = [];
        $scheduler->spawn('writer', Scenario::drain('writer', $batches, $receivedBatches));
        $scheduler->run();

        if (2 !== count($receivedBatches)) {
            throw new AssertionError('Writer should have received 2 batches');
        }

        $batchesSizes = array_map(
            static fn (mixed $batch): int => is_array($batch) ? count($batch) : throw new AssertionError('Batch should be an array'),
            $receivedBatches
        );

        if ([2, 2] !== $batchesSizes) {
            throw new AssertionError('Batches should have sizes 2, 2');
        }
    }
);

Scenario::scenario(
    'Batcher (size 1)',
    'closing the orders channel right away should generate no batch',
    static function () {
        $orders = new Channel(2, 'orders');
        $batches = new Channel(1, 'batches');

        $batcher = new Batcher(1, $orders, $batches);
        $scheduler = new Scheduler();
        $scheduler->spawn('transformer', Scenario::scripted(
            'transformer', [
                new Close($orders),
            ]
        ));
        $scheduler->spawn('batcher', $batcher());

        $receivedBatches = [];
        $scheduler->spawn('writer', Scenario::drain('writer', $batches, $receivedBatches));
        $scheduler->run();

        if (0 !== count($receivedBatches)) {
            throw new AssertionError('Writer should have received no batch');
        }
    }
);

Scenario::scenario(
    'Reader -> Transfomer -> batcher',
    sprintf('sum of batches and rejects should equal %d', $expectedTotalRows),
    static function () use ($expectedTotalRows) {
        $read = new Channel(2, 'read');
        $orders = new Channel(2, 'orders');
        $batches = new Channel(1, 'batches');
        $rejects = new Channel(1, 'rejects');

        $reader = new Reader(Paths::ORDERS_FILE_PATH, $read);
        $transformer = new Transformer($read, $orders, $rejects);
        $batchSize = 50;
        $batcher = new Batcher($batchSize, $orders, $batches);

        $scheduler = new Scheduler();
        $scheduler->spawn('reader', $reader());
        $scheduler->spawn('transformer', $transformer());
        $scheduler->spawn('batcher', $batcher());

        $receivedBatches = [];
        $scheduler->spawn('writer', Scenario::drain('writer', $batches, $receivedBatches));

        $receivedRejects = [];
        $scheduler->spawn('rejects', Scenario::drain('rejects', $rejects, $receivedRejects));
        $scheduler->run();

        $receivedOrdersCount = array_map(static fn (mixed $batch): int => is_array($batch) ? count($batch) : throw new AssertionError('Batches should be arrays'), $receivedBatches)
                                |> array_sum(...);

        if ($receivedOrdersCount + count($receivedRejects) !== $expectedTotalRows) {
            throw new AssertionError(sprintf('ReceiveOrdersCount (%d) + receivedRejectsCount (%d) should equal %d', $receivedOrdersCount, count($receivedRejects), $expectedTotalRows));
        }

        if (array_any(array_slice($receivedBatches, 0, -1), static fn (mixed $batch) => !is_array($batch) || count($batch) !== $batchSize)) {
            throw new AssertionError(sprintf('All batches except the last one should have %d orders', $batchSize));
        }

        $lastBatch = array_last($receivedBatches);
        if (!is_array($lastBatch)) {
            throw new AssertionError('Last received batch should be an array');
        }

        if (count($lastBatch) < 1) {
            throw new AssertionError('Last received batch should not be empty');
        }

        if ($batchSize < count($lastBatch)) {
            throw new AssertionError(sprintf('Last received batch should have at most %d orders', $batchSize));
        }
    }
);

Scenario::scenario(
    'Writer',
    'should not throw when writing orders',
    static function () {
        $batches = new Channel(1, 'batches');
        $writer = new Writer($batches);

        $scheduler = new Scheduler();
        $scheduler->spawn('writer', $writer());

        $d = new DateTimeImmutable('2026-10-16 12:00:00');
        $scheduler->spawn(
            'batcher',
            Scenario::scripted(
                'batcher', [
                    new Send($batches, [
                        new Order(1, 'Customer1', 10.50, $d),
                        new Order(2, 'Customer2', 14.50, $d),
                    ]),
                    new Send($batches, [
                        new Order(3, 'Customer3', 15.50, $d),
                        new Order(4, 'Customer4', 21.50, $d),
                    ]),
                    new Close($batches),
                ]
            )
        );

        $scheduler->run();
    }
);

Scenario::scenarioShouldThrow(
    'Writer',
    'should throw when a batch contains a non-order',
    LogicException::class,
    static function () {
        $batches = new Channel(1, 'batches');
        $writer = new Writer($batches);

        $scheduler = new Scheduler();
        $scheduler->spawn('writer', $writer());

        $d = new DateTimeImmutable('2026-10-16 12:00:00');
        $scheduler->spawn(
            'batcher',
            Scenario::scripted(
                'batcher', [
                    new Send($batches, [
                        new Order(1, 'Customer1', 10.50, $d),
                        'This is not an order',
                    ]),
                    new Close($batches),
                ]
            )
        );

        $scheduler->run();
    }
);

Scenario::scenarioShouldThrow(
    'Writer',
    'should throw on an empty batch',
    LogicException::class,
    static function () {
        $batches = new Channel(1, 'batches');
        $writer = new Writer($batches);

        $scheduler = new Scheduler();
        $scheduler->spawn('writer', $writer());

        $scheduler->spawn(
            'batcher',
            Scenario::scripted(
                'batcher', [
                    new Send($batches, []),
                    new Close($batches),
                ]
            )
        );

        $scheduler->run();
    }
);
