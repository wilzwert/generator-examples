<?php

namespace App\Stream\Provider;

use App\Stream\Decoder\MessageDecoder;
use App\Stream\Decoder\OpenRouterMessageDecoder;
use App\Stream\HttpMethod;
use App\Stream\Splitter\MessageSplitter;
use App\Stream\Splitter\SseMessageSplitter;
use App\Stream\StreamRequest;

final readonly class OpenRouterProvider implements Provider
{
    private const string URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private string $model,
        private string $apiKey,
    ) {
    }

    public function buildRequest(string $userMessage): StreamRequest
    {
        return new StreamRequest(
            url: self::URL,
            httpMethod: HttpMethod::POST,
            headers: [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            body: json_encode([
                'model' => $this->model,
                'messages' => [['role' => 'user', 'content' => $userMessage]],
                'stream' => true,
            ], \JSON_THROW_ON_ERROR),
        );
    }

    public function createMessageSplitter(): MessageSplitter
    {
        return new SseMessageSplitter();
    }

    public function createMessageDecoder(): MessageDecoder
    {
        return new OpenRouterMessageDecoder();
    }
}
