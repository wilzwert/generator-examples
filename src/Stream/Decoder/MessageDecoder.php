<?php

namespace App\Stream\Decoder;

use App\Stream\Event;
use App\Stream\Message;

/**
 * Gives meaning to provider messages: text chunks, end of answer, errors.
 */
interface MessageDecoder
{
    /**
     * @return \Generator<int, Event>
     */
    public function decode(Message $message): \Generator;
}
