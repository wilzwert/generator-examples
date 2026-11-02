<?php

namespace App\Pipeline;

use App\Pipeline\Command\Command;

class GeneratorTask implements Task
{
    private TaskStatus $status = TaskStatus::NOT_STARTED;

    private mixed $resumeWith = null;

    private ?\Throwable $failWith = null;

    /**
     * @param \Generator<int, Command, mixed, mixed> $generator
     */
    public function __construct(
        public readonly string $id,
        private readonly \Generator $generator,
    ) {
    }

    public function resume(): ?Command
    {
        if (TaskStatus::DONE === $this->status) {
            throw new \LogicException('Cannot resume a finished task');
        }

        if (TaskStatus::NOT_STARTED === $this->status) {
            $this->status = TaskStatus::STARTED;
            $next = $this->generator->current();
        } elseif (null !== $this->failWith) {
            /** @var ?Command $next */
            $next = $this->generator->throw($this->failWith);
            $this->failWith = null;
        } else {
            $next = $this->generator->send($this->resumeWith);
            $this->resumeWith = null;
        }

        // a generator only knows it is finished once resumed past its last yield:
        // in that case $next is not a yielded value, just null
        if (!$this->generator->valid()) {
            $this->status = TaskStatus::DONE;
        }

        return $next;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function resumeWith(mixed $data): void
    {
        if (TaskStatus::STARTED !== $this->status) {
            throw new \LogicException('Can only set a resume value on a running task');
        }
        $this->resumeWith = $data;
    }

    public function failWith(\Throwable $throwable): void
    {
        if (TaskStatus::STARTED !== $this->status) {
            throw new \LogicException('Task not started yet, cannot fail.');
        }
        $this->failWith = $throwable;
    }
}
