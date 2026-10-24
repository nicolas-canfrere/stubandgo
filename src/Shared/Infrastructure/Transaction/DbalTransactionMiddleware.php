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
            $this->rollBackQuietly();

            throw $exception;
        }

        // Outside the guard on purpose. When the commit (or savepoint release) fails at the driver,
        // DBAL decrements its nesting level in a "finally", so a rollBack() here would hit no
        // transaction (top level) or the enclosing one (savepoint). Other commit failures, such as
        // CommitFailedRollbackOnly, are thrown before any state change. In every case the commit
        // exception propagates unchanged and this middleware does not roll back.
        $this->connection->commit();

        return $envelope;
    }

    /**
     * A failing rollback must not hide the handler exception that caused it.
     */
    private function rollBackQuietly(): void
    {
        try {
            $this->connection->rollBack();
        } catch (\Throwable) {
        }
    }
}
