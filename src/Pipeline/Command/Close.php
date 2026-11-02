<?php

namespace App\Pipeline\Command;

use App\Pipeline\Channel;

final readonly class Close implements Command
{
    public function __construct(public Channel $channel)
    {
    }

    public function __toString(): string
    {
        return 'close';
    }
}
