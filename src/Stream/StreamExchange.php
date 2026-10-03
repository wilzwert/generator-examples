<?php

namespace App\Stream;

use App\Stream\Decoder\MessageDecoder;
use App\Stream\Provider\Provider;
use App\Stream\Splitter\MessageSplitter;

/**
 * One request sent to a provider and its streamed answer, read through the pipeline
 * bytes -> splitter -> messages -> decoder -> events.
 * Knows nothing about the transport: it is given bytes, and told when the stream ended.
 */
final class StreamExchange
{
    public readonly StreamRequest $request;

    private readonly MessageSplitter $messageSplitter;
    private readonly MessageDecoder $messageDecoder;
    private bool $doneSeen = false;

    public function __construct(
        private readonly string $name,
        Provider $provider,
        string $userMessage,
    ) {
        $this->request = $provider->buildRequest($userMessage);
        $this->messageSplitter = $provider->createMessageSplitter();
        $this->messageDecoder = $provider->createMessageDecoder();
    }

    public function receiveBytes(string $bytes): void
    {
        $this->messageSplitter->receiveBytes($bytes);
    }

    /**
     * @return \Generator<int, Event>
     */
    public function getEvents(): \Generator
    {
        foreach ($this->messageSplitter->getMessages() as $message) {
            yield from $this->wrap($this->messageDecoder->decode($message));
        }
    }

    /**
     * @return \Generator<int, Event>
     */
    public function finish(?string $transportError = null): \Generator
    {
        // when transport is already in error, there's no point getting other events
        if (null !== $transportError) {
            yield new Event(source: $this->name, body: '', done: true, error: $transportError);

            return;
        }

        foreach ($this->messageSplitter->flush() as $message) {
            yield from $this->wrap($this->messageDecoder->decode($message));
        }

        // The provider's end marker already said "done"; only the transport ending needs its own.
        if (!$this->doneSeen) {
            $this->doneSeen = true;

            yield new Event($this->name, '', done: true);
        }
    }

    /**
     * @param iterable<Event> $results
     *
     * @return \Generator<int, Event>
     */
    private function wrap(iterable $results): \Generator
    {
        foreach ($results as $result) {
            $this->doneSeen = $this->doneSeen || $result->done;

            yield new Event($this->name, $result->body, $result->done, $result->error);
        }
    }
}
