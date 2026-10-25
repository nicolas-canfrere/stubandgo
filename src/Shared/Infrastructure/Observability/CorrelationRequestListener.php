<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Takes the trace of an HTTP request from its valid "traceparent" header, or starts a new one, and returns it in the
 * response header (problem responses included).
 */
final readonly class CorrelationRequestListener
{
    private const string HEADER = 'traceparent';

    public function __construct(private CorrelationContext $context)
    {
    }

    /**
     * Runs before the router and the other request listeners, so their logs carry the trace.
     */
    #[AsEventListener(priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $header = (string) $event->getRequest()->headers->get(self::HEADER);
        $this->context->use(TraceParent::fromHeader($header) ?? TraceParent::generate());
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set(self::HEADER, $this->context->current()->toHeader());
    }
}
