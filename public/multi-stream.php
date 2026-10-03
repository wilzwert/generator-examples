<?php

require __DIR__.'/../vendor/autoload.php';

use App\Stream\MultiStream;

$providerNames = [/*'demo-1', 'demo-2', 'demo-3', */'open-router-gpt-4o'];

// Server-Sent Events: one JSON message per chunk, tagged with the answer it belongs to ("s").
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

$multiStream = new MultiStream();

foreach ($multiStream->run($providerNames, 'Tell me how you are') as $event) {
    if (connection_aborted()) {
        break;
    }

    echo 'data: ', json_encode($event, \JSON_THROW_ON_ERROR), "\n\n";
    flush();
}
