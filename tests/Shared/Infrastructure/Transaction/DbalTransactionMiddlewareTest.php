<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Transaction;

use App\Shared\Infrastructure\Transaction\DbalTransactionMiddleware;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class DbalTransactionMiddlewareTest extends TestCase
{
    public function testItCommitsAndReturnsTheEnvelope(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $envelope = new Envelope(new \stdClass());
        $result = new DbalTransactionMiddleware($connection)->handle($envelope, $this->stack(static fn(Envelope $e): Envelope => $e));

        self::assertSame($envelope, $result);
    }

    public function testItRollsBackAndRethrowsOnFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');

        $failure = new \RuntimeException('boom');

        try {
            new DbalTransactionMiddleware($connection)->handle(
                new Envelope(new \stdClass()),
                $this->stack(static fn(): never => throw $failure),
            );
            self::fail('The exception should have been rethrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testItRethrowsTheHandlerExceptionWhenRollbackFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('rollBack')->willThrowException(new \LogicException('rollback failed'));

        $failure = new \RuntimeException('boom');

        try {
            new DbalTransactionMiddleware($connection)->handle(
                new Envelope(new \stdClass()),
                $this->stack(static fn(): never => throw $failure),
            );
            self::fail('The handler exception should have been rethrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testItDoesNotRollBackWhenTheCommitItselfFails(): void
    {
        $commitFailure = new \RuntimeException('commit failed');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit')->willThrowException($commitFailure);
        $connection->expects(self::never())->method('rollBack');

        try {
            new DbalTransactionMiddleware($connection)->handle(
                new Envelope(new \stdClass()),
                $this->stack(static fn(Envelope $e): Envelope => $e),
            );
            self::fail('The commit exception should have been rethrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($commitFailure, $caught);
        }
    }

    /**
     * @param \Closure(Envelope): Envelope $next
     */
    private function stack(\Closure $next): StackInterface
    {
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn(new class($next) implements MiddlewareInterface {
            /**
             * @param \Closure(Envelope): Envelope $next
             */
            public function __construct(private readonly \Closure $next)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->next)($envelope);
            }
        });

        return $stack;
    }
}
