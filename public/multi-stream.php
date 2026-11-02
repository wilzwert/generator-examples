<?php

require __DIR__.'/../vendor/autoload.php';

use App\Stream\MultiStream;
use App\Stream\Provider\ProviderRegistry;

$registry = new ProviderRegistry();

// unsafe, do NOT do that in production without validation !
$prompt = $_GET['prompt'] ?? null;
// providers[] could hold anything: only keep strings
$providerNames = is_array($_GET['providers'] ?? null) ? array_values(array_filter($_GET['providers'], \is_string(...))) : [];

if (!is_string($prompt) || '' === $prompt || [] === $providerNames) {
    var_dump($_GET);
    exit('NOPE');
}

// Server-Sent Events: one JSON message per chunk, tagged with the answer it belongs to ("s").
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// keep running after a client disconnect, so connection_aborted() can stop the loop
// and the generator's finally block can release the curl handles
ignore_user_abort(true);

$multiStream = new MultiStream();

foreach ($multiStream->run($providerNames, $prompt) as $event) {
    if (connection_aborted()) {
        break;
    }

    echo 'data: ', json_encode($event, \JSON_THROW_ON_ERROR), "\n\n";
    flush();
}
