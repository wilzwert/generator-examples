<?php

namespace App\Pipeline;

use App\Pipeline\Command\Close;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Pipeline\Command\Send;

class Scheduler
{
    /**
     * @var \SplQueue<Task>
     */
    private \SplQueue $queue;

    /**
     * @var array<string, bool>
     */
    private array $tasksIds = [];

    /**
     * @var \SplObjectStorage<Channel, \SplQueue<Task>>
     */
    private \SplObjectStorage $parkedReceivers;
    /**
     * @var \SplObjectStorage<Channel, \SplQueue<ParkedSender>>
     */
    private \SplObjectStorage $parkedSenders;

    public function __construct()
    {
        $this->queue = new \SplQueue();
        $this->parkedReceivers = new \SplObjectStorage();
        $this->parkedSenders = new \SplObjectStorage();
    }

    /**
     * @param \Generator<int, Command, mixed, mixed> $generator
     */
    public function spawn(string $id, \Generator $generator): void
    {
        if (isset($this->tasksIds[$id])) {
            throw new \LogicException(\sprintf('Task with id %s already exists.', $id));
        }

        $task = new GeneratorTask($id, $generator);
        $this->queue->enqueue($task);
        $this->tasksIds[$id] = true;
    }

    public function run(): void
    {
        while (!$this->queue->isEmpty()) {
            $task = $this->queue->dequeue();

            $command = $task->resume();

            if (TaskStatus::DONE === $task->getStatus()) {
                continue;
            }

            if (!$command instanceof Command) {
                $command
                    |> get_debug_type(...)
                    |> (static fn ($x) => \sprintf('Task "%s" must yield a Command, got %s', $task->id, $x))
                    |> (fn ($x) => $this->fail($task, $x));
                continue;
            }

            match ($command::class) {
                Close::class => $this->handleClose($task, $command),
                Receive::class => $this->handleReceive($task, $command),
                Send::class => $this->handleSend($task, $command),
                default => throw new \LogicException('No handler for '.$command::class),
            };
        }

        // deadlocks : remaining parked tasks (receivers or senders)
        $this->assertNoParkedTasks();
    }

    private function assertNoParkedTasks(): void
    {
        $lines = [];
        foreach ($this->parkedReceivers as $channel) {
            foreach ($this->parkedReceiversOf($channel) as $receiver) {
                $lines[] = \sprintf('  - %s: waiting to receive on %s', $receiver->id, $channel);
            }
        }

        foreach ($this->parkedSenders as $channel) {
            foreach ($this->parkedSendersOf($channel) as $sender) {
                $lines[] = \sprintf('  - %s: waiting to send on %s', $sender->task->id, $channel);
            }
        }

        if ([] !== $lines) {
            throw new DeadlockException("Deadlock: all tasks are blocked\n".implode("\n", $lines));
        }
    }

    private function handleClose(Task $task, Close $command): void
    {
        $channel = $command->channel;

        if ($channel->isClosed()) {
            $this->fail($task, 'Channel already closed');

            return;
        }

        $channel->close();

        // parked receivers imply an empty buffer: the channel is now drained, answer with end of stream
        $receivers = $this->parkedReceiversOf($channel);
        while (!$receivers->isEmpty()) {
            $this->answer($receivers->dequeue());
        }

        // parked senders can never deliver their value
        $senders = $this->parkedSendersOf($channel);
        while (!$senders->isEmpty()) {
            $this->fail($senders->dequeue()->task, 'Channel closed while sending.');
        }

        $this->answer($task);
    }

    private function handleReceive(Task $task, Receive $command): void
    {
        $channel = $command->channel;

        if ($channel->isDrained()) {
            $this->answer($task);

            return;
        }

        if ($channel->isEmpty()) {
            $this->parkedReceiversOf($channel)->enqueue($task);

            return;
        }

        $this->answer($task, $channel->shift());

        // a slot just freed: hand it to the sender who has been waiting the longest
        if (!$this->parkedSendersOf($channel)->isEmpty()) {
            $sender = $this->parkedSendersOf($channel)->dequeue();
            $channel->push($sender->value);
            $this->answer($sender->task);
        }
    }

    private function handleSend(Task $task, Send $command): void
    {
        $channel = $command->channel;

        if ($channel->isClosed()) {
            $this->fail($task, 'Channel closed, cannot send.');

            return;
        }

        if ($channel->isFull()) {
            $this->parkedSendersOf($channel)->enqueue(new ParkedSender($task, $command->payload));

            return;
        }

        $channel->push($command->payload);

        if (!$this->parkedReceiversOf($channel)->isEmpty()) {
            $this->answer($this->parkedReceiversOf($channel)->dequeue(), $channel->shift());
        }

        $this->answer($task);
    }

    /**
     * Resumes the task with a value on its next turn.
     */
    private function answer(Task $task, mixed $value = null): void
    {
        $task->resumeWith($value);
        $this->queue->enqueue($task);
    }

    /**
     * Raises an exception at the task's yield on its next turn.
     */
    private function fail(Task $task, string $message): void
    {
        $task->failWith(new \LogicException($message));
        $this->queue->enqueue($task);
    }

    /**
     * @return \SplQueue<Task>
     */
    private function parkedReceiversOf(Channel $channel): \SplQueue
    {
        if (!isset($this->parkedReceivers[$channel])) {
            /** @var \SplQueue<Task> $queue */
            $queue = new \SplQueue();
            $this->parkedReceivers[$channel] = $queue;
        }

        return $this->parkedReceivers[$channel];
    }

    /**
     * @return \SplQueue<ParkedSender>
     */
    private function parkedSendersOf(Channel $channel): \SplQueue
    {
        if (!isset($this->parkedSenders[$channel])) {
            /** @var \SplQueue<ParkedSender> $queue */
            $queue = new \SplQueue();
            $this->parkedSenders[$channel] = $queue;
        }

        return $this->parkedSenders[$channel];
    }
}
