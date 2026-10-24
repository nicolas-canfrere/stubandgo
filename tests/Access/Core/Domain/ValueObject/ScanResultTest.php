<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Domain\ValueObject;

use App\Access\Core\Domain\ScanOutcome;
use App\Access\Core\Domain\ValueObject\ScanResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScanResultTest extends TestCase
{
    #[DataProvider('outcomeValues')]
    public function testOutcomeValues(ScanOutcome $outcome, string $value): void
    {
        self::assertSame($value, $outcome->value);
    }

    /**
     * @return iterable<string, array{ScanOutcome, string}>
     */
    public static function outcomeValues(): iterable
    {
        yield 'valid' => [ScanOutcome::Valid, 'valid'];
        yield 'already used' => [ScanOutcome::AlreadyUsed, 'already_used'];
        yield 'wrong event' => [ScanOutcome::WrongEvent, 'wrong_event'];
        yield 'unknown' => [ScanOutcome::Unknown, 'unknown'];
    }

    public function testExposesItsState(): void
    {
        $result = new ScanResult(ScanOutcome::AlreadyUsed, new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertSame(ScanOutcome::AlreadyUsed, $result->outcome);
        self::assertNotNull($result->firstUsedAt);
        self::assertSame('2026-12-31T19:30:00+00:00', $result->firstUsedAt->format(\DATE_ATOM));
        self::assertSame('UTC', $result->firstUsedAt->getTimezone()->getName());
    }

    public function testFirstUseIsOptional(): void
    {
        self::assertNull(new ScanResult(ScanOutcome::Valid, null)->firstUsedAt);
    }

    public function testEquals(): void
    {
        $at = new \DateTimeImmutable('2026-12-31T20:30:00Z');
        $result = new ScanResult(ScanOutcome::AlreadyUsed, $at);

        self::assertTrue($result->equals(new ScanResult(ScanOutcome::AlreadyUsed, $at->setTimezone(new \DateTimeZone('Europe/Paris')))));
        self::assertFalse($result->equals(new ScanResult(ScanOutcome::Valid, $at)));
        self::assertFalse($result->equals(new ScanResult(ScanOutcome::AlreadyUsed, $at->modify('+1 second'))));
        self::assertFalse($result->equals(new ScanResult(ScanOutcome::AlreadyUsed, null)));
        self::assertFalse(new ScanResult(ScanOutcome::Valid, null)->equals($result));
        self::assertTrue(new ScanResult(ScanOutcome::Valid, null)->equals(new ScanResult(ScanOutcome::Valid, null)));
    }
}
