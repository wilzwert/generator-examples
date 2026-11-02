<?php

namespace App\Generator;

use App\Console\ConsoleCommandRunner;
use Generator;

/**
 * Benchmark: export N UUIDv4 to a file, using either an array or a generator.
 *
 * Usage: bin/console benchmark [--memory-limit=256M] [--repetitions=5] [N ...]
 *        e.g. bin/console benchmark --memory-limit=256M --repetitions=10 500000 1000000
 *
 * Each measurement runs in its own PHP process (run($child = true)) so the
 * peak memory of one doesn't skew the other. The memory limit applies to
 * those child processes, where the work happens.
 */
final class Benchmark
{
    public const DEFAULT_REPETITIONS = 5;
    public const MIN_REPETITIONS = 2;
    public const MAX_REPETITIONS = 30;

    private const MODES = ['array', 'generator'];

    /** @throws \InvalidArgumentException if repetitions is outside MIN..MAX */
    public function __construct(
        private readonly string $memoryLimit = '-1',
        private readonly int $repetitions = self::DEFAULT_REPETITIONS,
        private readonly ConsoleCommandRunner $runner = new ConsoleCommandRunner(),
    ) {
        if ($repetitions < self::MIN_REPETITIONS || $repetitions > self::MAX_REPETITIONS) {
            throw new \InvalidArgumentException(\sprintf('repetitions must be between %d and %d, got %d', self::MIN_REPETITIONS, self::MAX_REPETITIONS, $repetitions));
        }
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr(\ord($b[6]) & 0x0F | 0x40);   // version 4
        $b[8] = \chr(\ord($b[8]) & 0x3F | 0x80);   // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Classic version: build and return the full array.
     *
     * @return list<string>
     */
    public function uuidsArray(int $n): array
    {
        $uuids = [];
        for ($i = 0; $i < $n; ++$i) {
            $uuids[] = $this->uuidV4();
        }

        return $uuids;
    }

    /** Generator version: UUIDs produced on demand. */
    public function uuidsGenerator(int $n): \Generator
    {
        for ($i = 0; $i < $n; ++$i) {
            yield $this->uuidV4();
        }
    }

    /**
     * The consumer stays the same: it accepts any iterable.
     *
     * @param iterable<string> $uuids
     * @param resource         $stream
     */
    public function exporter(iterable $uuids, $stream): int
    {
        $total = 0;
        foreach ($uuids as $uuid) {
            fwrite($stream, $uuid."\n");
            ++$total;
        }

        return $total;
    }

    /**
     * $child = true: run a single measurement, $args = [mode, n].
     * $child = false: orchestrate all sizes/modes, each measured by
     * re-invoking this same command as a child subprocess. $args = sizes.
     *
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    public function run(bool $child, array $args): array
    {
        return $child ? $this->measureOnce($args) : $this->runOrchestrator($args);
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function measureOnce(array $args): array
    {
        [$mode, $n] = $args;
        $n = (int) $n;
        $file = tmpfile();

        $memoryBefore = memory_get_usage();
        $start = hrtime(true);

        $source = 'array' === $mode ? $this->uuidsArray($n) : $this->uuidsGenerator($n);
        $this->exporter($source, $file);

        return [
            'ms' => (hrtime(true) - $start) / 1e6,
            'peak' => memory_get_peak_usage() - $memoryBefore,
        ];
    }

    /**
     * Orchestrator mode : iterates $this->repetitions times, collects and builds data.
     *
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function runOrchestrator(array $args): array
    {
        $sizes = array_map(intval(...), $args) ?: [10_000, 100_000, 1_000_000];

        $rows = [];
        foreach ($sizes as $n) {
            foreach (self::MODES as $mode) {
                try {
                    $measures = array_map(
                        fn () => $this->runChild($mode, $n),
                        range(1, $this->repetitions),
                    );
                } catch (\RuntimeException $e) {
                    $rows[] = ['n' => $n, 'mode' => $mode, 'error' => $e->getMessage()];
                    continue;
                }

                $rows[] = [
                    'n' => $n,
                    'mode' => $mode,
                    'ms' => $this->median(array_column($measures, 'ms')),
                    'peak' => (int) $this->median(array_column($measures, 'peak')),
                ];
            }
        }

        return ['repetitions' => $this->repetitions, 'rows' => $rows];
    }

    /**
     * Runs one repetition in a separate clean process, via the runner.
     * The child executes measureOnce() and sends its result back as JSON.
     *
     * @return array{ms: float, peak: int}
     */
    private function runChild(string $mode, int $n): array
    {
        $data = $this->runner->runJson('benchmark', ['--run', $mode, \sprintf('%d', $n)], ['memory_limit' => $this->memoryLimit]);

        if (!isset($data['ms'], $data['peak']) || !is_numeric($data['ms']) || !is_numeric($data['peak'])) {
            throw new \RuntimeException('benchmark child returned no measurement');
        }

        return ['ms' => (float) $data['ms'], 'peak' => (int) $data['peak']];
    }

    /**
     * @param list<float|int> $values
     */
    private function median(array $values): float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)];
    }
}
