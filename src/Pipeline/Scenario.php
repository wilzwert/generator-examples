<?php

namespace App\Pipeline;

use App\Pipeline\Command\Command;
use App\Pipeline\Command\Receive;
use App\Support\Format;

class Scenario
{
    private static function title(string $title, string $expected): string
    {
        return \sprintf("\nScenario\n=== %s\n--- expected: %s\n", $title, $expected);
    }

    private static function success(string $message): string
    {
        return \sprintf("\033[32m[OK]\033[0m %s\n", $message);
    }

    private static function failure(string $message): string
    {
        return \sprintf("\033[31m[ERROR]\033[0m %s\n", $message);
    }

    public static function scenario(string $title, string $expected, callable $run): void
    {
        echo self::title($title, $expected);
        try {
            $run();
            echo self::success($expected);
        } catch (\Throwable $e) {
            echo self::failure(\sprintf('!!! %s: %s', $e::class, $e->getMessage()));
        }
    }

    public static function scenarioShouldThrow(string $title, string $expected, string $exceptionClassName, callable $run): void
    {
        echo self::title($title, $expected);
        $exceptionThrown = null;
        try {
            $run();
        } catch (\Throwable $e) {
            $exceptionThrown = $e;
        }
        echo match (true) {
            null === $exceptionThrown => self::failure(\sprintf('Scenario should have thrown %s but no exception was thrown', $exceptionClassName)),
            $exceptionThrown instanceof $exceptionClassName => self::success(\sprintf('%s was thrown with message %s', $exceptionThrown::class, $exceptionThrown->getMessage())),
            default => self::failure(\sprintf('Scenario should have thrown %s but %s was thrown instead', $exceptionClassName, $exceptionThrown::class)),
        };
    }

    /**
     * Yields the given commands in order and logs each answer.
     * $catch: log channel errors and keep going instead of letting them crash the run.
     *
     * @param list<Command> $commands
     *
     * @return \Generator<int, Command, mixed, void>
     */
    public static function scripted(string $name, array $commands, bool $catch = false): \Generator
    {
        foreach ($commands as $command) {
            try {
                echo \sprintf("[%s] yielding command %s\n", $name, $command);
                $answer = yield $command;
                echo \sprintf("[%s] received answer %s\n", $name, Format::value($answer));
            } catch (\LogicException $e) {
                if (!$catch) {
                    throw $e;
                }
                echo \sprintf("[%s] %s failed: %s\n", $name, $command, $e->getMessage());
            }
        }
        echo \sprintf("[%s] done\n", $name);
    }

    /**
     * Receives values from a channel until null is received.
     *
     * @param list<mixed> $receivedValues
     *
     * @return \Generator<int, Command, mixed, void>
     */
    public static function drain(string $name, Channel $channel, array &$receivedValues = []): \Generator
    {
        while (null !== ($received = yield new Receive($channel))) {
            $receivedValues[] = $received;
        }
        echo \sprintf("[%s] done, received %d values\n", $name, \count($receivedValues));
    }
}
