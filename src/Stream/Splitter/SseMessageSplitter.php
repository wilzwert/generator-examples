<?php

namespace App\Stream\Splitter;

use App\Stream\Message;

/**
 * Server-Sent Events framing: events are separated by a blank line, and each event
 * is made of "field: value" lines. Only "event" and "data" matter here.
 */
final class SseMessageSplitter implements MessageSplitter
{
    private string $buffer = '';

    public function receiveBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function getMessages(): \Generator
    {
        // Separators are ASCII, so a complete event never ends in the middle of a UTF-8 character.
        while (preg_match('/\r?\n\r?\n/', $this->buffer, $matches, \PREG_OFFSET_CAPTURE)) {
            [$separator, $offset] = $matches[0];
            $block = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + \strlen($separator));

            $message = $this->parseEvent($block);
            if (null !== $message) {
                yield $message;
            }
        }
    }

    /**
     * The SSE spec says an event not terminated by a blank line is incomplete: drop it.
     */
    public function flush(): \Generator
    {
        $this->buffer = '';

        yield from [];
    }

    private function parseEvent(string $block): ?Message
    {
        $type = null;
        $data = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            // Empty lines can't happen inside a block; lines starting with ":" are comments (keep-alives).
            if ('' === $line || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }

            match ($field) {
                'event' => $type = $value,
                'data' => $data[] = $value,
                default => null, // "id", "retry": not needed here
            };
        }

        // A block without data (e.g. only comments) carries nothing to decode.
        return [] === $data ? null : new Message(implode("\n", $data), $type);
    }
}
