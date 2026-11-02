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
    private readonly string $baseUrl;

    public function __construct(private int $index)
    {
        $baseUrl = getenv('INTERNAL_BASE_URL');
        $this->baseUrl =  $baseUrl ?: 'http://localhost:8080';
    }

    public function buildRequest(string $userMessage): StreamRequest
    {
        return new StreamRequest(
            url: \sprintf('%s/example-stream.php?text=%d', $this->baseUrl, $this->index),
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
