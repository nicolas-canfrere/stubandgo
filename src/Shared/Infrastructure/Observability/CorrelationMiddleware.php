<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Stamps a dispatched message with the current trace; restores the trace of a message received from a transport.
 */
final readonly class CorrelationMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationContext $context)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationStamp::class);

        if (null === $stamp) {
            $envelope = $envelope->with(new CorrelationStamp($this->context->current()->toHeader()));
        } elseif (null !== $envelope->last(ReceivedStamp::class)) {
            $this->restore($stamp);
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function restore(CorrelationStamp $stamp): void
    {
        $traceParent = TraceParent::fromHeader($stamp->traceParent);

        if (null !== $traceParent) {
            $this->context->use($traceParent);
        }
    }
}
