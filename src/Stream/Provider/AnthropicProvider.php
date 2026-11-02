<?php

namespace App\Stream\Provider;

use App\Stream\Decoder\AnthropicMessageDecoder;
use App\Stream\Decoder\MessageDecoder;
use App\Stream\HttpMethod;
use App\Stream\Splitter\MessageSplitter;
use App\Stream\Splitter\SseMessageSplitter;
use App\Stream\StreamRequest;

final readonly class AnthropicProvider implements Provider
{
    private const string URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private string $model,
        private string $apiKey,
        private int $maxTokens = 4096,
    ) {
    }

    public function buildRequest(string $userMessage): StreamRequest
    {
        return new StreamRequest(
            url: self::URL,
            httpMethod: HttpMethod::POST,
            headers: [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            body: json_encode([
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
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
        return new AnthropicMessageDecoder();
    }
}
