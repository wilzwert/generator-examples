<?php

namespace App\Pipeline\Command;

use App\Pipeline\Channel;
use App\Support\Format;

final readonly class Send implements Command
{
    /**
     * @throws \LogicException on a null payload: null is reserved for end of stream
     */
    public function __construct(public Channel $channel, public mixed $payload)
    {
        if (null === $payload) {
            throw new \LogicException('Cannot send null: it is reserved for end of stream');
        }
    }

    public function __toString(): string
    {
        return 'send '.Format::value($this->payload);
    }
}
