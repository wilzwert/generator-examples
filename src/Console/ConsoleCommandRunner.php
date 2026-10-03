<?php

namespace App\Console;

/**
 * Spawns a bin/console command as a subprocess and decodes its JSON stdout.
 */
final class ConsoleCommandRunner
{
    private string $consolePath;

    public function __construct(?string $consolePath = null)
    {
        $this->consolePath = $consolePath ?? __DIR__.'/../../bin/console';
    }

    /**
     * @param array<int, string>        $args       passed to the command after its name
     * @param array<string, string|int> $iniOptions passed as -d key=value
     *
     * @return array<array-key, mixed>
     *
     * @throws \RuntimeException if the subprocess printed no valid JSON (e.g. a fatal error)
     */
    public function runJson(string $command, array $args = [], array $iniOptions = []): array
    {
        $output = shell_exec($this->buildCommand($command, $args, $iniOptions)) ?: '';
        $data = json_decode($output, true);

        if (!\is_array($data)) {
            throw new \RuntimeException(strtok(trim($output), "\n") ?: 'no output from subprocess');
        }

        return $data;
    }

    /**
     * @param array<int, string>        $args       passed to the command after its name
     * @param array<string, string|int> $iniOptions passed as -d key=value
     */
    private function buildCommand(string $command, array $args, array $iniOptions): string
    {
        $parts = [\PHP_BINARY];

        foreach ($iniOptions as $key => $value) {
            $parts[] = \sprintf('-d %s=%s', $key, $value);
        }

        $parts[] = escapeshellarg($this->consolePath);
        $parts[] = escapeshellarg($command);

        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }
}
