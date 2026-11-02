<?php

namespace App\Stream\Splitter;

use App\Stream\Message;

/**
 * Turns raw bytes into complete messages (framing only, no provider meaning).
 */
interface MessageSplitter
{
    public function receiveBytes(string $bytes): void;

    /**
     * @return \Generator<int, Message>
     */
    public function getMessages(): \Generator;

    /**
     * @return \Generator<int, Message>
     */
    public function flush(): \Generator;
}
