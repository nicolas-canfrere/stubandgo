<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Core\Domain\AdmissionRightRepositoryInterface;
use App\Access\Core\Domain\ValueObject\ScanResult;
use App\Access\Core\Domain\ValueObject\TicketToken;
use App\Shared\Identity\EventId;
use App\Tests\Access\AdmissionRightFixturesTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalAdmissionRightRepositoryTest extends KernelTestCase
{
    use AdmissionRightFixturesTrait;

    private AdmissionRightRepositoryInterface $rights;

    protected function setUp(): void
    {
        $this->rights = self::getContainer()->get(AdmissionRightRepositoryInterface::class);
    }

    public function testConsumingAnUnusedRightIsValidAndStoresTheDateInUtc(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();

        $result = $this->rights->consume(TicketToken::fromString($token), EventId::fromString($eventId), new \DateTimeImmutable('2026-10-24T14:00:00.123456+02:00'));

        self::assertEquals(ScanResult::valid(), $result);
        self::assertEquals(new \DateTimeImmutable('2026-10-24T12:00:00.123456Z'), $this->usedAt($token));
    }

    public function testConsumingAUsedRightIsAlreadyUsedWithTheFirstDate(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        $this->rights->consume(TicketToken::fromString($token), EventId::fromString($eventId), new \DateTimeImmutable('2026-10-24T12:00:00.5Z'));

        $result = $this->rights->consume(TicketToken::fromString($token), EventId::fromString($eventId), new \DateTimeImmutable('2026-10-24T12:05:00Z'));

        self::assertEquals(ScanResult::alreadyUsed(new \DateTimeImmutable('2026-10-24T12:00:00.5Z')), $result);
        self::assertEquals(new \DateTimeImmutable('2026-10-24T12:00:00.5Z'), $this->usedAt($token));
    }

    public function testConsumingForAnotherEventIsWrongEventAndKeepsTheRightUnused(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();

        $result = $this->rights->consume(TicketToken::fromString($token), EventId::generate(), new \DateTimeImmutable('2026-10-24T12:00:00Z'));

        self::assertEquals(ScanResult::wrongEvent(), $result);
        self::assertNull($this->usedAt($token));
    }

    public function testConsumingAUsedRightForAnotherEventIsWrongEvent(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        $this->rights->consume(TicketToken::fromString($token), EventId::fromString($eventId), new \DateTimeImmutable('2026-10-24T12:00:00Z'));

        $result = $this->rights->consume(TicketToken::fromString($token), EventId::generate(), new \DateTimeImmutable('2026-10-24T12:05:00Z'));

        self::assertEquals(ScanResult::wrongEvent(), $result);
        self::assertEquals(new \DateTimeImmutable('2026-10-24T12:00:00Z'), $this->usedAt($token));
    }

    public function testConsumingAnUnknownTokenIsUnknown(): void
    {
        $result = $this->rights->consume(TicketToken::fromString($this->unknownToken()), EventId::generate(), new \DateTimeImmutable('2026-10-24T12:00:00Z'));

        self::assertEquals(ScanResult::unknown(), $result);
    }

    public function testConsumingOneRightLeavesTheOthersUnused(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        ['token' => $other] = $this->issueAdmissionRight($eventId);

        $this->rights->consume(TicketToken::fromString($token), EventId::fromString($eventId), new \DateTimeImmutable('2026-10-24T12:00:00Z'));

        self::assertNull($this->usedAt($other));
    }

    private function usedAt(string $token): ?\DateTimeImmutable
    {
        $usedAt = self::getContainer()->get(Connection::class)->fetchOne('SELECT used_at FROM admission_right WHERE token = ?', [$token]);
        self::assertTrue(null === $usedAt || \is_string($usedAt));

        return null === $usedAt ? null : new \DateTimeImmutable($usedAt);
    }
}
