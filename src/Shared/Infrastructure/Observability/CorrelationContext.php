<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Trace of the work in progress: set from the HTTP request or the received message, generated on first use otherwise.
 * Reset between the messages of a worker.
 */
final class CorrelationContext implements ResetInterface
{
    private ?TraceParent $current = null;

    public function current(): TraceParent
    {
        return $this->current ??= TraceParent::generate();
    }

    public function use(TraceParent $traceParent): void
    {
        $this->current = $traceParent;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
