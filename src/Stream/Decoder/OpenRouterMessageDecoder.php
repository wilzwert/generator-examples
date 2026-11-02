<?php

namespace App\Stream\Decoder;

use App\Stream\Event;
use App\Stream\Message;

/**
 * OpenAI-style chat completion chunks, as relayed by OpenRouter:
 * data: {"choices":[{"delta":{"content":"Hello"}}]} ... then data: [DONE].
 */
final class OpenRouterMessageDecoder implements MessageDecoder
{
    public function decode(Message $message): \Generator
    {
        if ('[DONE]' === $message->data) {
            yield new Event(source: '', body: '', done: true);

            return;
        }

        $payload = json_decode($message->data, true);
        if (!\is_array($payload)) {
            yield new Event(source: '', body: '', done: true, error: 'Invalid JSON from provider');

            return;
        }

        /** @var array{error?: array{message?: string}, choices?: list<array{delta?: array{content?: ?string}}>} $payload */

        // Errors after the stream started still arrive with HTTP 200, as a chunk.
        if (isset($payload['error'])) {
            yield new Event(source: '', body: '', done: true, error: $payload['error']['message'] ?? 'Unknown provider error');

            return;
        }

        $content = $payload['choices'][0]['delta']['content'] ?? '';
        if ('' !== $content) {
            yield new Event(source: '', body: $content);
        }
    }
}
