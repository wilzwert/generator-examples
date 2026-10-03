<?php

namespace App\Stream\Provider;

use App\Stream\Decoder\MessageDecoder;
use App\Stream\Splitter\MessageSplitter;
use App\Stream\StreamRequest;

/**
 * Stateless description of a streaming provider: how to request it, and how to read its answer.
 * Anything holding per-stream state is created fresh for each exchange.
 */
interface Provider
{
    public function buildRequest(string $userMessage): StreamRequest;

    public function createMessageSplitter(): MessageSplitter;

    public function createMessageDecoder(): MessageDecoder;
}
