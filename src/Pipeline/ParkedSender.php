<?php

namespace App\Pipeline;

final readonly class ParkedSender
{
    public function __construct(public Task $task, public mixed $value)
    {
    }
}
