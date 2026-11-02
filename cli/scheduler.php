<?php

use App\Pipeline\Channel;
use App\Pipeline\Command\Close;
use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Pipeline\Command\Send;
use App\Pipeline\DeadlockException;
use App\Pipeline\Scenario;
use App\Pipeline\Scheduler;

/**
 * @return Generator<int, Command, mixed, mixed>
 */
function producer(Channel $out): Generator
{
    for ($i = 1; $i <= 5; ++$i) {
        echo "Producer produces $i\n";
        yield new Send($out, $i);
    }

    yield new Close($out);
}

/**
 * @return Generator<int, Command, mixed, mixed>
 */
function consumer(Channel $in): Generator
{
    while (null !== ($value = yield new Receive($in))) {
        echo 'Consumer got '.var_export($value, true)."\n";
    }
    echo "Consumer done\n";
}

Scenario::scenario('producer first', 'Producer produces 5 values, consumers reads them, clean finish', static function () {
    $channel = new Channel(10, 'numbers');
    $scheduler = new Scheduler();
    $scheduler->spawn('producer', producer($channel));
    $scheduler->spawn('consumer', consumer($channel));
    $scheduler->run();
});

Scenario::scenario('consumer first', 'Consumer alternates between parking and values,', static function () {
    $channel = new Channel(10, 'numbers');
    $scheduler = new Scheduler();
    $scheduler->spawn('consumer', consumer($channel));
    $scheduler->spawn('producer', producer($channel));
    $scheduler->run();
});

Scenario::scenarioShouldThrow(
    'Producer without consumer',
    'Producer parked on its 3 value, then deadlock',
    DeadlockException::class,
    static function () {
        $channel = new Channel(2, 'numbers');
        $scheduler = new Scheduler();
        $scheduler->spawn('producer', producer($channel));
        $scheduler->run();
    }
);

Scenario::scenarioShouldThrow(
    'Consumer without producer',
    'Consumer parked on its first receive, then deadlock',
    DeadlockException::class,
    static function () {
        $channel = new Channel(2, 'numbers');
        $scheduler = new Scheduler();
        $scheduler->spawn('consumer', consumer($channel));
        $scheduler->run();
    });

Scenario::scenario('fixed count consumer: 6th receive gets null', 'consumer gets 1, 2, then null', static function () {
    $ch = new Channel(10, 'numbers');
    $s = new Scheduler();
    $s->spawn('producer', Scenario::scripted('producer', [new Send($ch, 1), new Send($ch, 2), new Close($ch)]));
    $s->spawn('consumer', Scenario::scripted('consumer', [new Receive($ch), new Receive($ch), new Receive($ch)]));
    $s->run();
});

Scenario::scenarioShouldThrow(
    'send after close, task does not catch',
    'producer crashes the run with "Channel is closed"',
    LogicException::class,
    static function () {
        $ch = new Channel(2, 'numbers');
        $s = new Scheduler();
        $s->spawn('producer', Scenario::scripted('producer', [new Send($ch, 1), new Close($ch), new Send($ch, 2)]));
        $s->run();
    }
);

Scenario::scenario('Close without sending', 'Consumer parks, then gets null', static function () {
    $ch = new Channel(2, 'numbers');
    $s = new Scheduler();
    $s->spawn('consumer', Scenario::scripted('consumer', [new Receive($ch)]));
    $s->spawn('producer', Scenario::scripted('producer', [new Close($ch)]));
    $s->run();
});

Scenario::scenario('Task catches and continues', '2 failures logged, then "producer done". Nothing lost.', static function () {
    $ch = new Channel(2, 'numbers');
    $s = new Scheduler();
    $s->spawn(
        'producer',
        Scenario::scripted(
            'producer', [
                new Send($ch, 1), new Close($ch), new Send($ch, 2), new Send($ch, 3),
            ],
            true)
    );
    $s->run();
});

Scenario::scenario('closed while a sender is parked', 'B: "Channel closed while sending", then "B done"? ', static function () {
    $ch = new Channel(1, 'numbers');
    $s = new Scheduler();
    $s->spawn(
        'A',
        Scenario::scripted(
            'A', [
                new Send($ch, 1), new Close($ch),
            ]));
    $s->spawn(
        'B',
        Scenario::scripted(
            'B', [
                new Send($ch, 2),
            ], true));

    $s->run();
});

Scenario::scenarioShouldThrow(
    'Two channels (ab and ba), one task receives on ba then sends on ab, the other receives on ab then sends on ba',
    'Classic deadlock',
    DeadlockException::class,
    static function () {
        $ch1 = new Channel(2, 'ab');
        $ch2 = new Channel(3, 'ba');
        $s = new Scheduler();
        $s->spawn(
            'A',
            Scenario::scripted(
                'A', [
                    new Receive($ch2), new Send($ch1, '1'),
                ]
            )
        );
        $s->spawn(
            'B',
            Scenario::scripted(
                'B', [
                    new Receive($ch1), new Send($ch2, '2'),
                ]
            )
        );
        $s->run();
    }
);
