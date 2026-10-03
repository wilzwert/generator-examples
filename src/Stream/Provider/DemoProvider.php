<?php

namespace App\Stream\Provider;

use App\Stream\Decoder\DemoMessageDecoder;
use App\Stream\Decoder\MessageDecoder;
use App\Stream\HttpMethod;
use App\Stream\Splitter\MessageSplitter;
use App\Stream\Splitter\PlainTextMessageSplitter;
use App\Stream\StreamRequest;

final readonly class DemoProvider implements Provider
{
    private const string BASE_URL = 'http://localhost:8080';

    public function __construct(private int $index)
    {
    }

    public function buildRequest(string $userMessage): StreamRequest
    {
        return new StreamRequest(
            url: \sprintf('%s/example-stream.php?text=%d', self::BASE_URL, $this->index),
            httpMethod: HttpMethod::GET,
            headers: [],
            body: '',
        );
    }

    public function createMessageSplitter(): MessageSplitter
    {
        return new PlainTextMessageSplitter();
    }

    public function createMessageDecoder(): MessageDecoder
    {
        return new DemoMessageDecoder();
    }
}
