<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Observability\CorrelationMiddleware;
use App\Shared\Infrastructure\Observability\CorrelationStamp;
use App\Shared\Infrastructure\Observability\TraceParent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CorrelationMiddlewareTest extends TestCase
{
    private const string INCOMING = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private CorrelationContext $context;
    private TraceParent $current;

    protected function setUp(): void
    {
        $this->context = new CorrelationContext();
        $this->current = TraceParent::generate();
        $this->context->use($this->current);
    }

    public function testDispatchedMessageIsStampedWithTheCurrentTraceParent(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass()));

        self::assertEquals([new CorrelationStamp($this->current->toHeader())], $envelope->all(CorrelationStamp::class));
        self::assertSame($this->current, $this->context->current());
    }

    public function testExistingStampIsKeptAndDoesNotChangeTheContextOnDispatch(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass(), [new CorrelationStamp(self::INCOMING)]));

        self::assertEquals([new CorrelationStamp(self::INCOMING)], $envelope->all(CorrelationStamp::class));
        self::assertSame($this->current, $this->context->current());
    }

    public function testReceivedMessageRestoresItsTraceParent(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass(), [new CorrelationStamp(self::INCOMING), new ReceivedStamp('async')]));

        self::assertSame(self::INCOMING, $this->context->current()->toHeader());
        self::assertEquals([new CorrelationStamp(self::INCOMING)], $envelope->all(CorrelationStamp::class));
    }

    public function testReceivedMessageWithInvalidStampKeepsTheCurrentContext(): void
    {
        $this->handle(new Envelope(new \stdClass(), [new CorrelationStamp('garbage'), new ReceivedStamp('async')]));

        self::assertSame($this->current, $this->context->current());
    }

    public function testReceivedMessageWithoutStampIsStampedWithTheCurrentTraceParent(): void
    {
        $envelope = $this->handle(new Envelope(new \stdClass(), [new ReceivedStamp('scheduler')]));

        self::assertEquals([new CorrelationStamp($this->current->toHeader())], $envelope->all(CorrelationStamp::class));
        self::assertSame($this->current, $this->context->current());
    }

    private function handle(Envelope $envelope): Envelope
    {
        return new CorrelationMiddleware($this->context)->handle($envelope, new StackMiddleware());
    }
}
