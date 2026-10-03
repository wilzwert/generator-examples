<?php

namespace App\Stream\Splitter;

use App\Stream\Message;

/**
 * In plain text mode (our Demo classes), the bytes are the messages.
 */
class PlainTextMessageSplitter implements MessageSplitter
{
    private string $remainingBytes = '';

    public function receiveBytes(string $bytes): void
    {
        $this->remainingBytes .= $bytes;
    }

    public function getMessages(): \Generator
    {
        $complete = $this->completeUtf8Length();
        if (0 === $complete) {
            return;
        }

        $bytes = substr($this->remainingBytes, 0, $complete);
        $this->remainingBytes = substr($this->remainingBytes, $complete);
        yield new Message($bytes);
    }

    public function flush(): \Generator
    {
        yield from $this->getMessages();
    }

    /**
     * Length of the prefix of $this->remainingBytes that ends on a complete UTF-8 character,
     * so a multibyte character split across two network reads is not sent half-way.
     */
    private function completeUtf8Length(): int
    {
        $length = \strlen($this->remainingBytes);

        for ($back = 1; $back <= min(3, $length); ++$back) {
            $byte = \ord($this->remainingBytes[$length - $back]);

            if (($byte & 0xC0) === 0x80) {
                continue; // continuation byte, keep looking for the lead byte
            }

            $needed = match (true) {
                $byte >= 0xF0 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 1,
            };

            return $back < $needed ? $length - $back : $length;
        }

        return $length;
    }
}
