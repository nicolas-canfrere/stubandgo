<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\CommandLoggingMiddleware;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureConflictException;
use App\Tests\Shared\Infrastructure\Outbox\Fixtures\StoreOutboxMessage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CommandLoggingMiddlewareTest extends TestCase
{
    private const string SECRET = 'secret-command-content';

    private TestHandler $handler;
    private MockClock $clock;
    private Envelope $envelope;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->clock = new MockClock('2026-10-24 12:00:00.900000');
        $this->envelope = new Envelope(new StoreOutboxMessage(self::SECRET, false));
    }

    public function testHandledCommandIsLoggedWithItsClassAndDuration(): void
    {
        $handled = $this->envelope->with(new HandledStamp(null, 'handler'));

        $result = $this->handle(function () use ($handled): Envelope {
            $this->clock->sleep(0.2505);

            return $handled;
        });

        self::assertSame($handled, $result);
        $record = $this->singleRecord();
        self::assertSame(Level::Info, $record->level);
        self::assertSame('command.handled', $record->message);
        self::assertSame(['command' => StoreOutboxMessage::class, 'duration_ms' => 250.5], $record->context);
    }

    public function testDomainExceptionIsLoggedAsRejectionWithItsErrorCode(): void
    {
        $exception = new FixtureConflictException('conflict');

        $this->assertRethrown($exception, $exception);

        $record = $this->singleRecord();
        self::assertSame(Level::Warning, $record->level);
        self::assertSame('command.rejected', $record->message);
        self::assertSame(['command' => StoreOutboxMessage::class, 'error_code' => $exception->errorCode()], $record->context);
    }

    public function testDomainExceptionWrappedByTheBusIsClassifiedButRethrownWrapped(): void
    {
        $domain = new FixtureConflictException('conflict');
        $wrapper = new HandlerFailedException($this->envelope, ['first' => $domain, 'second' => new \RuntimeException('second')]);

        $this->assertRethrown($wrapper, $wrapper);

        $record = $this->singleRecord();
        self::assertSame(Level::Warning, $record->level);
        self::assertSame(['command' => StoreOutboxMessage::class, 'error_code' => $domain->errorCode()], $record->context);
    }

    public function testUnexpectedExceptionIsLoggedAsFailureWithTheException(): void
    {
        $exception = new \RuntimeException('boom');

        $this->assertRethrown($exception, $exception);

        $record = $this->singleRecord();
        self::assertSame(Level::Error, $record->level);
        self::assertSame('command.failed', $record->message);
        self::assertSame(['command' => StoreOutboxMessage::class, 'exception' => $exception], $record->context);
    }

    public function testFirstWrappedExceptionDecidesTheClassification(): void
    {
        $unexpected = new \RuntimeException('first');
        $wrapper = new HandlerFailedException($this->envelope, ['first' => $unexpected, 'second' => new FixtureConflictException('conflict')]);

        $this->assertRethrown($wrapper, $wrapper);

        $record = $this->singleRecord();
        self::assertSame(Level::Error, $record->level);
        self::assertSame(['command' => StoreOutboxMessage::class, 'exception' => $unexpected], $record->context);
    }

    private function assertRethrown(\Throwable $thrown, \Throwable $expected): void
    {
        try {
            $this->handle(static fn(): never => throw $thrown);
            self::fail('The exception should have been rethrown.');
        } catch (\Throwable $caught) {
            self::assertSame($expected, $caught);
        }
    }

    /**
     * @param \Closure(): Envelope $next
     */
    private function handle(\Closure $next): Envelope
    {
        $middleware = new CommandLoggingMiddleware(new Logger('test', [$this->handler]), $this->clock);

        return $middleware->handle($this->envelope, new StackMiddleware(new class($next) implements MiddlewareInterface {
            /**
             * @param \Closure(): Envelope $next
             */
            public function __construct(private readonly \Closure $next)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->next)();
            }
        }));
    }

    private function singleRecord(): LogRecord
    {
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        self::assertStringNotContainsString(self::SECRET, json_encode(array_map(static fn(LogRecord $r): array => $r->toArray(), $records), \JSON_THROW_ON_ERROR));

        return $records[0];
    }
}
