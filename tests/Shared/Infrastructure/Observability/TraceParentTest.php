<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Observability\TraceParent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TraceParentTest extends TestCase
{
    private const string VALID = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testValidHeaderIsKeptAsIs(): void
    {
        $traceParent = TraceParent::fromHeader(self::VALID);

        self::assertNotNull($traceParent);
        self::assertSame(self::VALID, $traceParent->toHeader());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $traceParent->traceId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'all-zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'];
        yield 'unknown version' => ['01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        yield 'uppercase hex' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'];
        yield 'short trace id' => ['00-4bf92f3577b34da6a3ce929d0e0e473-00f067aa0ba902b7-01'];
        yield 'short parent id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b-01'];
        yield 'short flags' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-1'];
        yield 'non hex' => ['00-4bf92f3577b34da6a3ce929d0e0e473g-00f067aa0ba902b7-01'];
        yield 'leading garbage' => ['x00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];
        yield 'trailing garbage' => [self::VALID . '-x'];
        yield 'trailing newline' => [self::VALID . "\n"];
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderIsRejected(string $header): void
    {
        self::assertNull(TraceParent::fromHeader($header));
    }

    public function testGeneratedTraceParentIsAValidSampledHeader(): void
    {
        $header = TraceParent::generate()->toHeader();

        self::assertMatchesRegularExpression('/\A00-[0-9a-f]{32}-[0-9a-f]{16}-01\z/', $header);
        self::assertNotNull(TraceParent::fromHeader($header));
    }

    public function testGeneratedTraceParentsAreUnique(): void
    {
        $first = TraceParent::generate();
        $second = TraceParent::generate();

        self::assertNotSame($first->traceId(), $second->traceId());
        self::assertNotSame(substr($first->toHeader(), 36), substr($second->toHeader(), 36));
    }

    public function testTraceIdIsTheSecondField(): void
    {
        $traceParent = TraceParent::generate();

        self::assertSame(explode('-', $traceParent->toHeader())[1], $traceParent->traceId());
    }
}
