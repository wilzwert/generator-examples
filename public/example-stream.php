<?php

require __DIR__.'/../vendor/autoload.php';

use App\Stream\Demo\DemoLlm;

// ?text=N picks one of the demo answers (0 to 3), otherwise one is picked at random.
$index = isset($_GET['text']) &&  false !== filter_var($_GET['text'], FILTER_VALIDATE_INT) ? (int) $_GET['text'] : null;

if (null !== $index && !array_key_exists($index, DemoLlm::TEXTS)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('text must be between 0 and '.(count(DemoLlm::TEXTS) - 1)."\n");
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
// Stops nginx from buffering the stream when it sits in front of FrankenPHP.
header('X-Accel-Buffering: no');

// Output buffering would hold the chunks back until the response is complete.
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

foreach (DemoLlm::stream(DemoLlm::pick($index)) as $chunk) {
    if (connection_aborted()) {
        break;
    }

    echo $chunk;
    flush();
}
