<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Observability\TraceParent;
use PHPUnit\Framework\TestCase;

final class CorrelationContextTest extends TestCase
{
    public function testCurrentIsGeneratedOnceThenKept(): void
    {
        $context = new CorrelationContext();

        self::assertSame($context->current(), $context->current());
    }

    public function testUsedTraceParentBecomesCurrent(): void
    {
        $context = new CorrelationContext();
        $context->current();
        $traceParent = TraceParent::generate();

        $context->use($traceParent);

        self::assertSame($traceParent, $context->current());
    }

    public function testResetForgetsTheCurrentTraceParent(): void
    {
        $context = new CorrelationContext();
        $traceParent = TraceParent::generate();
        $context->use($traceParent);

        $context->reset();

        self::assertNotSame($traceParent->traceId(), $context->current()->traceId());
    }
}
