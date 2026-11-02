<?php

use App\Pipeline\Channel;

function expectLogicException(callable $fn, string $message): void
{
    try {
        $fn();
        throw new AssertionError($message);
    } catch (LogicException $exception) {
    }
}

$channel = new Channel(2);

$channel->push('first');
$channel->push('second');

assert(!$channel->isClosed());
assert($channel->isFull());

expectLogicException(static fn () => $channel->push('third'), 'Push on full channel must throw');

$value = $channel->shift();
assert('first' === $value);

expectLogicException(static fn () => $channel->push(null), 'Push on null value must throw');

$channel->close();
assert($channel->isClosed());
assert(!$channel->isDrained());
$value = $channel->shift();
assert('second' === $value);

expectLogicException(static fn () => $channel->close(), 'Double close must throw');

expectLogicException(static fn () => $channel->push('fourth'), 'Push on closed channel must throw');

expectLogicException(static fn () => $channel->shift(), 'Shift on empty channel must throw');

assert($channel->isDrained());
