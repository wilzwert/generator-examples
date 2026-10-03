<?php

namespace App\Stream\Decoder;

use App\Stream\Event;
use App\Stream\Message;

/**
 * Claude Messages API stream: only text deltas, the end of the message and errors matter here.
 * Everything else (message_start, ping, thinking deltas...) is skipped.
 */
final class AnthropicMessageDecoder implements MessageDecoder
{
    public function decode(Message $message): \Generator
    {
        $payload = json_decode($message->data, true);
        if (!\is_array($payload)) {
            yield new Event(source: '', body: '', done: true, error: 'Invalid JSON from provider');

            return;
        }

        /** @var array{type?: string, delta?: array{type?: string, text?: string}, error?: array{type?: string, message?: string}} $payload */
        switch ($payload['type'] ?? null) {
            case 'content_block_delta':
                if ('text_delta' === ($payload['delta']['type'] ?? null)) {
                    yield new Event(source: '', body: $payload['delta']['text'] ?? '');
                }
                break;

            case 'message_stop':
                yield new Event(source: '', body: '', done: true);
                break;

            case 'error':
                // Can arrive mid-stream with HTTP 200, e.g. overloaded_error.
                $error = \sprintf('%s: %s', $payload['error']['type'] ?? 'error', $payload['error']['message'] ?? '');
                yield new Event(source: '', body: '', done: true, error: $error);
                break;
        }
    }
}
