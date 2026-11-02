<?php

namespace App\Stream\Provider;

use App\Stream\Decoder\MessageDecoder;
use App\Stream\Splitter\MessageSplitter;
use App\Stream\StreamRequest;

/**
 * Description of a streaming provider: how to request it, how to read receive bytes into messages and decode them
 */
interface Provider
{
    public function buildRequest(string $userMessage): StreamRequest;

    public function createMessageSplitter(): MessageSplitter;

    public function createMessageDecoder(): MessageDecoder;
}
