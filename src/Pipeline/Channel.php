<?php

namespace App\Pipeline;

class Channel implements \Stringable
{
    /**
     * @var \SplQueue<mixed>
     */
    private \SplQueue $queue;

    private bool $closed = false;

    public function __construct(public readonly int $capacity, public readonly string $name = '')
    {
        if ($this->capacity < 1) {
            throw new \LogicException('Channel capacity must be at least 1');
        }

        $this->queue = new \SplQueue();
    }

    public function isFull(): bool
    {
        return $this->queue->count() >= $this->capacity;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->queue->count();
    }

    /**
     * @throws \LogicException if the channel is closed, full or on a null value
     */
    public function push(mixed $value): void
    {
        if (null === $value) {
            throw new \LogicException('Value cannot be null');
        }

        if ($this->isClosed()) {
            throw new \LogicException('Channel is closed');
        }

        if ($this->isFull()) {
            throw new \LogicException('Channel is full');
        }

        $this->queue->enqueue($value);
    }

    /**
     * @phpstan-impure
     *
     * @throws \LogicException if the channel is empty
     */
    public function shift(): mixed
    {
        if ($this->isEmpty()) {
            throw new \LogicException('Channel is empty');
        }

        return $this->queue->shift();
    }

    /**
     * @throws \LogicException if the channel is already closed
     */
    public function close(): void
    {
        if ($this->closed) {
            throw new \LogicException('Channel already closed');
        }

        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isDrained(): bool
    {
        return $this->isClosed() && $this->isEmpty();
    }

    public function __toString(): string
    {
        return '' !== $this->name ? \sprintf('"%s"', $this->name) : '#'.spl_object_id($this);
    }
}
