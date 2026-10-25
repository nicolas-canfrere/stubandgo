<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Observability\CorrelationLogProcessor;
use App\Shared\Infrastructure\Observability\TraceParent;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class CorrelationLogProcessorTest extends TestCase
{
    public function testRecordGetsTheCurrentTraceIdAndKeepsItsExtra(): void
    {
        $context = new CorrelationContext();
        $traceParent = TraceParent::generate();
        $context->use($traceParent);
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', ['a' => 1], ['other' => 'value']);

        $processed = new CorrelationLogProcessor($context)($record);

        self::assertSame(['other' => 'value', 'trace_id' => $traceParent->traceId()], $processed->extra);
        self::assertSame('hello', $processed->message);
        self::assertSame(['a' => 1], $processed->context);
    }
}
