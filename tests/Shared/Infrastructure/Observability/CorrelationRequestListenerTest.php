<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Observability\CorrelationRequestListener;
use App\Shared\Infrastructure\Observability\TraceParent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The HTTP behaviour is covered end to end by CorrelationHttpTest; this checks what a functional test cannot reach.
 */
final class CorrelationRequestListenerTest extends TestCase
{
    public function testSubRequestDoesNotReplaceTheTraceParentOfTheMainRequest(): void
    {
        $context = new CorrelationContext();
        $main = TraceParent::generate();
        $context->use($main);
        $request = Request::create('/');
        $request->headers->set('traceparent', TraceParent::generate()->toHeader());

        new CorrelationRequestListener($context)->onRequest(
            new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST),
        );

        self::assertSame($main, $context->current());
    }
}
