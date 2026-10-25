<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Application;

use App\Access\Core\Application\Command\ScanTicket;
use App\Access\Core\Application\Query\GetScanAttempt;
use App\Access\Core\Application\Query\ScanAttemptView;
use App\Access\Core\Domain\Exception\ScanAttemptNotFoundException;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Tests\Access\AdmissionRightFixturesTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class ScanTicketTest extends KernelTestCase
{
    use AdmissionRightFixturesTrait;
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-20T20:00:00.250000+01:00';

    private MockClock $clock;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable(self::NOW));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
    }

    public function testFirstScanIsValid(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();

        self::assertEquals(new ScanAttemptView('valid', null), $this->scan($eventId, $token));
    }

    public function testSecondScanIsAlreadyUsedWithFirstDate(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        $this->scan($eventId, $token);
        $this->clock->sleep(90);

        $view = $this->scan($eventId, $token);

        self::assertSame('already_used', $view->outcome);
        self::assertNotNull($view->firstUsedAt);
        self::assertSame('2026-11-20T19:00:00.250000+00:00', $view->firstUsedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $view->firstUsedAt->getTimezone()->getName());
    }

    public function testScanForOtherEventIsWrongEventAndDoesNotConsume(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();

        self::assertEquals(new ScanAttemptView('wrong_event', null), $this->scan(EventId::generate()->toString(), $token));
        self::assertEquals(new ScanAttemptView('valid', null), $this->scan($eventId, $token));
    }

    public function testUnknownTokenIsUnknown(): void
    {
        ['eventId' => $eventId] = $this->issueAdmissionRight();

        self::assertEquals(new ScanAttemptView('unknown', null), $this->scan($eventId, $this->unknownToken()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'short' => ['abc'];
        yield 'empty' => [''];
        yield '500 characters' => [str_repeat('a', 500)];
        yield '43 characters outside base64url' => [str_repeat('+', 43)];
        yield 'NUL byte' => ["\0"];
        yield 'invalid UTF-8' => ["\xFF\xFE"];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenReturnsUnknownNot500(string $token): void
    {
        ['eventId' => $eventId] = $this->issueAdmissionRight();

        self::assertEquals(new ScanAttemptView('unknown', null), $this->scan($eventId, $token));
    }

    public function testEveryScanIsRecorded(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        ['token' => $otherEventToken] = $this->issueAdmissionRight();
        $unknown = $this->unknownToken();
        $long = str_repeat('b', 500);

        foreach ([$token, $token, $otherEventToken, $unknown, "x\0y", $long] as $scanned) {
            $this->scan($eventId, $scanned);
            $this->clock->sleep(1);
        }

        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            "SELECT token, outcome, to_char(first_used_at AT TIME ZONE 'UTC', 'HH24:MI:SS') AS first_used_at, to_char(scanned_at AT TIME ZONE 'UTC', 'HH24:MI:SS') AS scanned_at
             FROM scan_attempt WHERE event_id = ? ORDER BY scanned_at",
            [$eventId],
        );
        self::assertSame([
            ['token' => $token, 'outcome' => 'valid', 'first_used_at' => null, 'scanned_at' => '19:00:00'],
            ['token' => $token, 'outcome' => 'already_used', 'first_used_at' => '19:00:00', 'scanned_at' => '19:00:01'],
            ['token' => $otherEventToken, 'outcome' => 'wrong_event', 'first_used_at' => null, 'scanned_at' => '19:00:02'],
            ['token' => $unknown, 'outcome' => 'unknown', 'first_used_at' => null, 'scanned_at' => '19:00:03'],
            ['token' => "x\u{FFFD}y", 'outcome' => 'unknown', 'first_used_at' => null, 'scanned_at' => '19:00:04'],
            ['token' => str_repeat('b', 128), 'outcome' => 'unknown', 'first_used_at' => null, 'scanned_at' => '19:00:05'],
        ], $rows);
    }

    public function testScanOfAnUnknownWellFormedEventIsRecorded(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();
        $eventId = EventId::generate()->toString();

        $this->scan($eventId, $token);
        $this->clock->sleep(1);
        $this->scan($eventId, $this->unknownToken());

        self::assertSame(['wrong_event', 'unknown'], $this->recordedOutcomes($eventId));
    }

    public function testMalformedEventIdIsRejectedWithoutRecordingTheScan(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();
        $scanAttemptId = (string) new Ulid();

        try {
            self::getContainer()->get(CommandBusInterface::class)->dispatch(new ScanTicket($scanAttemptId, 'not-an-event', $token));
            self::fail('A malformed event identifier must be rejected.');
        } catch (InvalidValueException) {
        }

        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM scan_attempt WHERE id = ?', [$scanAttemptId]));
    }

    public function testUnknownScanAttemptIsNotFound(): void
    {
        $this->expectException(ScanAttemptNotFoundException::class);

        self::getContainer()->get(QueryBusInterface::class)->ask(new GetScanAttempt((string) new Ulid()));
    }

    private function scan(string $eventId, string $token): ScanAttemptView
    {
        $scanAttemptId = (string) new Ulid();
        self::getContainer()->get(CommandBusInterface::class)->dispatch(new ScanTicket($scanAttemptId, $eventId, $token));

        return self::getContainer()->get(QueryBusInterface::class)->ask(new GetScanAttempt($scanAttemptId));
    }

    /**
     * @return list<mixed>
     */
    private function recordedOutcomes(string $eventId): array
    {
        return self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT outcome FROM scan_attempt WHERE event_id = ? ORDER BY scanned_at', [$eventId]);
    }
}
