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

    public function testNamedConstructorsSetTheOutcomeWithoutDate(): void
    {
        self::assertSame(ScanOutcome::Valid, ScanResult::valid()->outcome());
        self::assertSame(ScanOutcome::WrongEvent, ScanResult::wrongEvent()->outcome());
        self::assertSame(ScanOutcome::Unknown, ScanResult::unknown()->outcome());
        self::assertNull(ScanResult::valid()->firstUsedAt());
        self::assertNull(ScanResult::wrongEvent()->firstUsedAt());
        self::assertNull(ScanResult::unknown()->firstUsedAt());
    }

    public function testAlreadyUsedCarriesTheFirstUseInUtc(): void
    {
        $result = ScanResult::alreadyUsed(new \DateTimeImmutable('2026-12-31T20:30:00+01:00'));

        self::assertSame(ScanOutcome::AlreadyUsed, $result->outcome());
        self::assertNotNull($result->firstUsedAt());
        self::assertSame('2026-12-31T19:30:00+00:00', $result->firstUsedAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $result->firstUsedAt()->getTimezone()->getName());
    }

    public function testEquals(): void
    {
        $at = new \DateTimeImmutable('2026-12-31T20:30:00Z');
        $result = ScanResult::alreadyUsed($at);

        self::assertTrue($result->equals(ScanResult::alreadyUsed($at->setTimezone(new \DateTimeZone('Europe/Paris')))));
        self::assertFalse($result->equals(ScanResult::alreadyUsed($at->modify('+1 second'))));
        self::assertFalse($result->equals(ScanResult::valid()));
        self::assertFalse(ScanResult::valid()->equals($result));
        self::assertTrue(ScanResult::valid()->equals(ScanResult::valid()));
        self::assertFalse(ScanResult::valid()->equals(ScanResult::unknown()));
        self::assertFalse(ScanResult::wrongEvent()->equals(ScanResult::unknown()));
    }
}
