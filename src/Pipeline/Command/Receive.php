<?php

namespace App\Pipeline\Command;

use App\Pipeline\Channel;

final readonly class Receive implements Command
{
    public function __construct(public Channel $channel)
    {
    }

    public function __toString(): string
    {
        return 'receive';
    }
}
