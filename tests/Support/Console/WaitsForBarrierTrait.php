<?php

declare(strict_types=1);

namespace App\Tests\Support\Console;

/**
 * Lets a test-only console command started by ParallelRunner wait until the shared barrier file exists.
 */
trait WaitsForBarrierTrait
{
    private const int BARRIER_TIMEOUT_MICROSECONDS = 60_000_000;
    private const int POLL_MICROSECONDS = 200;

    private function waitFor(string $barrier): void
    {
        $waited = 0;
        clearstatcache();

        while (!file_exists($barrier)) {
            if ($waited >= self::BARRIER_TIMEOUT_MICROSECONDS) {
                throw new \RuntimeException('The barrier never opened.');
            }

            usleep(self::POLL_MICROSECONDS);
            $waited += self::POLL_MICROSECONDS;
            clearstatcache();
        }
    }
}
