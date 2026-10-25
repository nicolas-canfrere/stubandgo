<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\Exception;

use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Access\Core\Domain\ValueObject\ScanAttemptId;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\OrderId;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testTicketBundleNotFound(): void
    {
        $id = OrderId::generate();
        $e = TicketBundleNotFoundException::forOrder($id);

        self::assertInstanceOf(AbstractNotFoundException::class, self::asThrowable($e));
        self::assertSame('ticket_bundle_not_found', $e->errorCode());
        self::assertSame(\sprintf('No ticket bundle for order "%s".', $id->toString()), $e->getMessage());
    }

    public function testScanAttemptNotFound(): void
    {
        $id = ScanAttemptId::generate();
        $e = ScanAttemptNotFoundException::forId($id);

        self::assertInstanceOf(AbstractNotFoundException::class, self::asThrowable($e));
        self::assertSame('scan_attempt_not_found', $e->errorCode());
        self::assertSame(\sprintf('Scan attempt "%s" not found.', $id->toString()), $e->getMessage());
    }

    private static function asThrowable(\Throwable $e): \Throwable
    {
        return $e;
    }
}
