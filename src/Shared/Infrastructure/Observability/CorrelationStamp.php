<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the traceparent header of the dispatching context with a message, through the transport if any.
 */
final readonly class CorrelationStamp implements StampInterface
{
    public function __construct(public string $traceParent)
    {
    }
}
