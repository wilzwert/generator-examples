<?php

// Required here too, not just by bin/console: the orchestrator recurses
// into this same command through bin/console as a subprocess.
require __DIR__.'/../vendor/autoload.php';

use App\Generator\Benchmark;

// Split "--key=value" options from positional arguments.
$options = [];
$positional = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}

$child = ($positional[0] ?? '') === '--run';
$args = array_slice($positional, $child ? 1 : 0);
$memoryLimit = $options['memory-limit'] ?? '-1';

try {
    $benchmark = new Benchmark(
        memoryLimit: $memoryLimit,
        repetitions: (int) ($options['repetitions'] ?? Benchmark::DEFAULT_REPETITIONS),
    );
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage()."\n");
    exit(1);
}

/** @var array{repetitions: int, rows: list<array{n: int, mode: string, error: string}|array{n: int, mode: string, ms: float, peak: int}>} $result */
$result = $benchmark->run($child, $args);

if ($child) {
    echo json_encode($result);
    exit;
}

function humanReadable(int $bytes): string
{
    return match (true) {
        $bytes >= 1 << 20 => sprintf('%.1f MB', $bytes / (1 << 20)),
        $bytes >= 1 << 10 => sprintf('%.1f KB', $bytes / (1 << 10)),
        default => "$bytes B",
    };
}

printf(
    "PHP %s — median over %d runs, memory_limit=%s\n\n",
    \PHP_VERSION,
    $result['repetitions'],
    $memoryLimit,
);
printf("%-12s %-11s %12s %14s\n", 'N', 'Method', 'Duration', 'Peak memory');
echo str_repeat('-', 52), "\n";

$lastN = null;
foreach ($result['rows'] as $row) {
    if (null !== $lastN && $row['n'] !== $lastN) {
        echo "\n";
    }

    if (isset($row['error'])) {
        printf("%-12s %-11s FAILED: %s\n", number_format($row['n'], 0, '.', ' '), $row['mode'], $row['error']);
    } else {
        printf(
            "%-12s %-11s %9.1f ms %14s\n",
            number_format($row['n'], 0, '.', ' '),
            $row['mode'],
            $row['ms'],
            humanReadable($row['peak']),
        );
    }

    $lastN = $row['n'];
}
