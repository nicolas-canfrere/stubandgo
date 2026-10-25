<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Exception\AbstractDomainException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Logs the outcome of every command: its class and duration, a business rejection as a warning with its error code,
 * anything else as an error with the exception. Never the command's properties (PayOrder carries a card number).
 */
final readonly class CommandLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $command = $envelope->getMessage()::class;
        $startedAt = $this->clock->now();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $this->logFailure($command, $exception);

            throw $exception;
        }

        $this->logger->info('command.handled', ['command' => $command, 'duration_ms' => self::milliseconds($startedAt, $this->clock->now())]);

        return $envelope;
    }

    private static function milliseconds(\DateTimeImmutable $from, \DateTimeImmutable $to): int|float
    {
        $microseconds = ($to->getTimestamp() - $from->getTimestamp()) * 1_000_000 + $to->getMicrosecond() - $from->getMicrosecond();

        return $microseconds / 1_000;
    }

    /**
     * The bus wraps handler exceptions: the first wrapped one decides, as for the bus wrappers that unwrap it.
     */
    private function logFailure(string $command, \Throwable $exception): void
    {
        if ($exception instanceof HandlerFailedException) {
            $exception = array_first($exception->getWrappedExceptions()) ?? $exception;
        }

        if ($exception instanceof AbstractDomainException) {
            $this->logger->warning('command.rejected', ['command' => $command, 'error_code' => $exception->errorCode()]);

            return;
        }

        $this->logger->error('command.failed', ['command' => $command, 'exception' => $exception]);
    }
}
