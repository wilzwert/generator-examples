<?php

namespace App\Pipeline;

use App\Pipeline\Command\Command;

interface Task
{
    public string $id { get; }

    public function resume(): ?Command;

    public function getStatus(): TaskStatus;

    public function resumeWith(mixed $data): void;

    public function failWith(\Throwable $throwable): void;
}
