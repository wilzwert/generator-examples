<?php

use App\Etl\Batcher;
use App\Etl\Reader;
use App\Etl\RejectLogger;
use App\Etl\Transformer;
use App\Etl\Writer;
use App\Pipeline\Channel;
use App\Pipeline\Scenario;
use App\Pipeline\Scheduler;
use App\Support\Paths;

$keep = 'keep' === ($argv[1] ?? '');

const CHANNEL_CAPACITY = 10;

$read = new Channel(CHANNEL_CAPACITY, 'read');
$orders = new Channel(CHANNEL_CAPACITY, 'orders');
$batches = new Channel(CHANNEL_CAPACITY, 'batches');
$rejects = new Channel(CHANNEL_CAPACITY, 'rejects');

$reader = new Reader(Paths::ORDERS_FILE_PATH, $read);
$transformer = new Transformer($read, $orders, $rejects);
$batcher = new Batcher(100, $orders, $batches);

$logger = new RejectLogger($rejects);
$scheduler = new Scheduler();
$scheduler->spawn('reader', $reader());
$scheduler->spawn('transformer', $transformer());
$scheduler->spawn('batcher', $batcher());
if ($keep) {
    $kept = [];
    $scheduler->spawn('writer', Scenario::drain('writer', $batches, $kept));
} else {
    $writer = new Writer($batches);
    $scheduler->spawn('writer', $writer());
}
$scheduler->spawn('logger', $logger());

$scheduler->run();

printf("peak memory: %.2f MB\n", memory_get_peak_usage() / 1024 / 1024);

if ($keep) {
    printf("Kept %d batches.\n", count($kept));
}
