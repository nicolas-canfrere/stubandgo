<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs console commands of the test environment in parallel processes. Each command boots, connects to the
 * database, prints "ready" and waits on a shared barrier file, so that they all start their work at the same time.
 */
final class ParallelRunner
{
    private const string READY = 'ready';
    private const float TIMEOUT_SECONDS = 120.0;

    /**
     * @param list<non-empty-list<string>> $commands      console arguments of each process (command name first)
     * @param (callable(): void)|null      $beforeRelease called once every process is ready, right before the barrier opens
     *
     * @return list<string> what each process printed after "ready", trimmed, in the order of $commands
     */
    public static function run(array $commands, ?callable $beforeRelease = null): array
    {
        $barrier = sys_get_temp_dir() . '/stubandgo-barrier-' . bin2hex(random_bytes(8));
        $processes = array_map(static fn(array $arguments): Process => self::start($arguments, $barrier), $commands);

        try {
            foreach ($processes as $process) {
                self::waitUntilReady($process);
            }

            if (null !== $beforeRelease) {
                $beforeRelease();
            }

            touch($barrier);

            return array_map(self::outcome(...), $processes);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }

            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    /**
     * The arguments follow "--", so a value starting with a dash (a base64url token can) is never read as an option.
     *
     * @param non-empty-list<string> $arguments
     */
    private static function start(array $arguments, string $barrier): Process
    {
        $name = array_shift($arguments);
        $process = new Process(
            ['php', 'bin/console', $name, '--barrier=' . $barrier, '--', ...$arguments],
            \dirname(__DIR__, 2),
            ['APP_ENV' => 'test', 'XDEBUG_MODE' => 'off', 'SHELL_VERBOSITY' => '0'],
            null,
            self::TIMEOUT_SECONDS,
        );
        $process->start();

        return $process;
    }

    private static function waitUntilReady(Process $process): void
    {
        $process->waitUntil(static fn(): bool => str_contains($process->getOutput(), self::READY));

        if (!str_contains($process->getOutput(), self::READY)) {
            throw new \RuntimeException(\sprintf("A parallel process stopped before being ready:\n%s\n%s", $process->getOutput(), $process->getErrorOutput()));
        }
    }

    private static function outcome(Process $process): string
    {
        $process->wait();
        $output = $process->getOutput();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(\sprintf("A parallel process failed:\n%s\n%s", $output, $process->getErrorOutput()));
        }

        return trim(substr($output, strpos($output, self::READY) + \strlen(self::READY)));
    }
}
