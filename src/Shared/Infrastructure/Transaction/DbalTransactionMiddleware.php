<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Transaction;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class DbalTransactionMiddleware implements MiddlewareInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->connection->beginTransaction();

        try {
            $envelope = $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        // Outside the guard: DBAL already resets its nesting level when a commit fails.
        $this->connection->commit();

        return $envelope;
    }
}
