<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventLockedException;
use App\Catalog\Core\Domain\Exception\EventNotDraftException;
use App\Catalog\Core\Domain\Exception\EventNotPublishedException;
use App\Catalog\Core\Domain\Exception\EventStartInPastException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Exception\AbstractConflictException;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\Capacity;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testDraftExposesItsState(): void
    {
        $id = EventId::generate();
        $organizationId = OrganizationId::generate();
        $title = EventTitle::fromString('Concert');
        $description = EventDescription::fromString('Un super concert');
        $venue = Venue::fromString('La Cigale');
        $startsAt = new \DateTimeImmutable('2026-12-31T20:30:00+01:00');
        $capacity = Capacity::of(100);
        $tiers = [self::tier('Plein')];

        $event = Event::draft($id, $organizationId, $title, $description, $venue, $startsAt, $capacity, $tiers, self::now());

        self::assertSame($id, $event->id());
        self::assertSame($organizationId, $event->organizationId());
        self::assertSame($title, $event->title());
        self::assertSame($description, $event->description());
        self::assertSame($venue, $event->venue());
        self::assertSame('2026-12-31T19:30:00+00:00', $event->startsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $event->startsAt()->getTimezone()->getName());
        self::assertTrue($startsAt == $event->startsAt());
        self::assertSame($capacity, $event->capacity());
        self::assertSame($tiers, $event->priceTiers());
        self::assertSame(EventStatus::Draft, $event->status());
        self::assertFalse($event->isLocked());
    }

    public function testDraftRejectsDuplicatePriceTierIds(): void
    {
        $id = PriceTierId::generate();

        $this->expectException(InvalidValueException::class);

        self::draftWith([
            new PriceTier($id, PriceTierLabel::fromString('Plein'), Money::EUR(1000)),
            new PriceTier($id, PriceTierLabel::fromString('Réduit'), Money::EUR(500)),
        ]);
    }

    public function testDraftAcceptsDistinctPriceTierIds(): void
    {
        $event = self::draftWith([self::tier('Plein'), self::tier('Réduit')]);

        self::assertCount(2, $event->priceTiers());
    }

    public function testDraftAcceptsEmptyPriceTiers(): void
    {
        self::assertSame([], self::draftWith([])->priceTiers());
    }

    public function testPublishReturnsEventPublishedWithCapacity(): void
    {
        $event = self::draftWith([self::tier('Plein')]);

        $published = $event->publish(self::now());

        self::assertSame($event->id(), $published->eventId);
        self::assertSame($event->capacity(), $published->capacity);
        self::assertSame(EventStatus::Published, $event->status());
    }

    public function testCannotPublishTwice(): void
    {
        $event = self::draftWith([self::tier('Plein')]);
        $event->publish(self::now());

        try {
            $event->publish(self::now());
            self::fail('Expected an exception.');
        } catch (EventAlreadyPublishedException $e) {
            self::assertSame('event_already_published', $e->errorCode());
        }
    }

    public function testCannotPublishWithoutPriceTier(): void
    {
        $event = self::draftWith([]);

        try {
            $event->publish(self::now());
            self::fail('Expected an exception.');
        } catch (EventWithoutPriceTierException $e) {
            self::assertSame('event_without_price_tier', $e->errorCode());
            self::assertSame(EventStatus::Draft, $event->status());
        }
    }

    public function testLockAndUnlock(): void
    {
        $event = self::draftWith([self::tier('Plein')]);

        $event->lock();
        self::assertTrue($event->isLocked());

        $event->unlock();
        self::assertFalse($event->isLocked());
    }

    public function testLockAndUnlockAreIdempotent(): void
    {
        $event = self::draftWith([self::tier('Plein')]);

        $event->lock();
        $event->lock();
        self::assertTrue($event->isLocked());

        $event->unlock();
        $event->unlock();
        self::assertFalse($event->isLocked());
    }

    public function testReconstituteRestoresPublishedLockedState(): void
    {
        $id = EventId::generate();
        $organizationId = OrganizationId::generate();
        $title = EventTitle::fromString('Concert');
        $description = EventDescription::fromString('Un super concert');
        $venue = Venue::fromString('La Cigale');
        $startsAt = new \DateTimeImmutable('2026-12-31T20:30:00+01:00');
        $capacity = Capacity::of(100);
        $tiers = [self::tier('Plein')];

        $event = Event::reconstitute($id, $organizationId, $title, $description, $venue, $startsAt, $capacity, $tiers, EventStatus::Published, true);

        self::assertSame($id, $event->id());
        self::assertSame($organizationId, $event->organizationId());
        self::assertSame($title, $event->title());
        self::assertSame($description, $event->description());
        self::assertSame($venue, $event->venue());
        self::assertSame('2026-12-31T19:30:00+00:00', $event->startsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $event->startsAt()->getTimezone()->getName());
        self::assertSame($capacity, $event->capacity());
        self::assertSame($tiers, $event->priceTiers());
        self::assertSame(EventStatus::Published, $event->status());
        self::assertTrue($event->isLocked());
    }

    public function testReconstituteRestoresDraftUnlockedState(): void
    {
        $event = Event::reconstitute(
            EventId::generate(),
            OrganizationId::generate(),
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            new \DateTimeImmutable('2027-01-01 20:00:00'),
            Capacity::of(100),
            [],
            EventStatus::Draft,
            false,
        );

        self::assertSame(EventStatus::Draft, $event->status());
        self::assertFalse($event->isLocked());
    }

    public function testReconstitutedPublishedEventCannotBePublishedAgain(): void
    {
        $event = Event::reconstitute(
            EventId::generate(),
            OrganizationId::generate(),
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            new \DateTimeImmutable('2027-01-01 20:00:00'),
            Capacity::of(100),
            [self::tier('Plein')],
            EventStatus::Published,
            false,
        );

        $this->expectException(EventAlreadyPublishedException::class);

        $event->publish(self::now());
    }

    #[DataProvider('pastOrPresentStarts')]
    public function testDraftRejectsAStartThatIsNotInTheFuture(string $startsAt): void
    {
        try {
            Event::draft(
                EventId::generate(),
                OrganizationId::generate(),
                EventTitle::fromString('Concert'),
                EventDescription::fromString('Un super concert'),
                Venue::fromString('La Cigale'),
                new \DateTimeImmutable($startsAt),
                Capacity::of(100),
                [],
                self::now(),
            );
            self::fail('Expected an exception.');
        } catch (EventStartInPastException $e) {
            self::assertSame('event_start_in_past', $e->errorCode());
        }
    }

    public function testDraftAcceptsAStartJustAfterNow(): void
    {
        $event = Event::draft(
            EventId::generate(),
            OrganizationId::generate(),
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            new \DateTimeImmutable('2026-10-24T14:00:00.000001+02:00'),
            Capacity::of(100),
            [],
            self::now(),
        );

        self::assertSame('2026-10-24T12:00:00.000001', $event->startsAt()->format('Y-m-d\TH:i:s.u'));
    }

    #[DataProvider('pastOrPresentStarts')]
    public function testPublishRejectsAStartThatIsNotInTheFuture(string $startsAt): void
    {
        $event = self::reconstituted(EventStatus::Draft, false, $startsAt);

        try {
            $event->publish(self::now());
            self::fail('Expected an exception.');
        } catch (EventStartInPastException) {
            self::assertSame(EventStatus::Draft, $event->status());
        }
    }

    public function testPublishAcceptsAStartJustAfterNow(): void
    {
        $event = self::reconstituted(EventStatus::Draft, false, '2026-10-24T12:00:00.000001+00:00');

        $event->publish(self::now());

        self::assertSame(EventStatus::Published, $event->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pastOrPresentStarts(): iterable
    {
        yield 'now' => ['2026-10-24T14:00:00+02:00'];
        yield 'one microsecond ago' => ['2026-10-24T11:59:59.999999+00:00'];
        yield 'last year' => ['2025-10-24T12:00:00+00:00'];
    }

    public function testReviseReplacesEveryFieldOfADraft(): void
    {
        $event = self::draftWith([self::tier('Plein')]);
        $title = EventTitle::fromString('Nouveau titre');
        $description = EventDescription::fromString('Nouvelle description');
        $venue = Venue::fromString('Le Zénith');
        $capacity = Capacity::of(42);
        $tiers = [self::tier('Réduit'), self::tier('Gratuit')];

        $event->revise($title, $description, $venue, new \DateTimeImmutable('2027-02-01T21:00:00+01:00'), $capacity, $tiers, self::now());

        self::assertSame($title, $event->title());
        self::assertSame($description, $event->description());
        self::assertSame($venue, $event->venue());
        self::assertSame('2027-02-01T20:00:00+00:00', $event->startsAt()->format(\DATE_ATOM));
        self::assertSame('UTC', $event->startsAt()->getTimezone()->getName());
        self::assertSame($capacity, $event->capacity());
        self::assertSame($tiers, $event->priceTiers());
        self::assertSame(EventStatus::Draft, $event->status());
        self::assertFalse($event->isLocked());
    }

    public function testReviseAcceptsAStartJustAfterNow(): void
    {
        $event = self::draftWith([]);

        self::revise($event, '2026-10-24T12:00:00.000001+00:00');

        self::assertSame('2026-10-24T12:00:00.000001', $event->startsAt()->format('Y-m-d\TH:i:s.u'));
    }

    /**
     * @return iterable<string, array{EventStatus, bool, class-string<AbstractConflictException>, string}>
     */
    public static function unrevisableEvents(): iterable
    {
        yield 'locked draft' => [EventStatus::Draft, true, EventLockedException::class, 'event_locked'];
        yield 'locked published' => [EventStatus::Published, true, EventLockedException::class, 'event_locked'];
        yield 'published' => [EventStatus::Published, false, EventNotDraftException::class, 'event_not_draft'];
    }

    /**
     * @param class-string<AbstractConflictException> $exception
     */
    #[DataProvider('unrevisableEvents')]
    public function testReviseRefusesALockedOrPublishedEvent(EventStatus $status, bool $locked, string $exception, string $errorCode): void
    {
        $event = self::reconstituted($status, $locked);

        try {
            self::revise($event, '2027-02-01T20:00:00+00:00');
            self::fail('Expected an exception.');
        } catch (AbstractConflictException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame($errorCode, $e->errorCode());
            self::assertSame('Concert', $event->title()->toString());
            self::assertSame($status, $event->status());
        }
    }

    #[DataProvider('pastOrPresentStarts')]
    public function testReviseRefusesAStartThatIsNotInTheFuture(string $startsAt): void
    {
        $event = self::draftWith([]);

        try {
            self::revise($event, $startsAt);
            self::fail('Expected an exception.');
        } catch (EventStartInPastException) {
            self::assertSame('Concert', $event->title()->toString());
            self::assertSame('2027-01-01T20:00:00+00:00', $event->startsAt()->format(\DATE_ATOM));
        }
    }

    public function testReviseRefusesDuplicatePriceTierIds(): void
    {
        $event = self::draftWith([]);
        $id = PriceTierId::generate();

        try {
            $event->revise(
                EventTitle::fromString('Nouveau titre'),
                EventDescription::fromString('Nouvelle description'),
                Venue::fromString('Le Zénith'),
                new \DateTimeImmutable('2027-02-01T20:00:00+00:00'),
                Capacity::of(42),
                [
                    new PriceTier($id, PriceTierLabel::fromString('Plein'), Money::EUR(1000)),
                    new PriceTier($id, PriceTierLabel::fromString('Réduit'), Money::EUR(500)),
                ],
                self::now(),
            );
            self::fail('Expected an exception.');
        } catch (InvalidValueException) {
            self::assertSame('Concert', $event->title()->toString());
            self::assertSame([], $event->priceTiers());
        }
    }

    public function testUnpublishTurnsAnUnlockedPublishedEventBackIntoADraft(): void
    {
        $event = self::reconstituted(EventStatus::Published, false);

        $event->unpublish();

        self::assertSame(EventStatus::Draft, $event->status());
        self::assertFalse($event->isLocked());
    }

    public function testUnpublishRefusesALockedEvent(): void
    {
        $event = self::reconstituted(EventStatus::Published, true);

        try {
            $event->unpublish();
            self::fail('Expected an exception.');
        } catch (EventLockedException $e) {
            self::assertSame('event_locked', $e->errorCode());
            self::assertSame(EventStatus::Published, $event->status());
        }
    }

    public function testUnpublishRefusesADraft(): void
    {
        $event = self::reconstituted(EventStatus::Draft, false);

        try {
            $event->unpublish();
            self::fail('Expected an exception.');
        } catch (EventNotPublishedException $e) {
            self::assertSame('event_not_published', $e->errorCode());
            self::assertSame(EventStatus::Draft, $event->status());
        }
    }

    public function testUnpublishChecksTheLockFirst(): void
    {
        $this->expectException(EventLockedException::class);

        self::reconstituted(EventStatus::Draft, true)->unpublish();
    }

    public function testUnpublishedEventCanBePublishedAgain(): void
    {
        $event = self::reconstituted(EventStatus::Published, false);
        $event->unpublish();

        $event->publish(self::now());

        self::assertSame(EventStatus::Published, $event->status());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-24T12:00:00+00:00');
    }

    private static function revise(Event $event, string $startsAt): void
    {
        $event->revise(
            EventTitle::fromString('Nouveau titre'),
            EventDescription::fromString('Nouvelle description'),
            Venue::fromString('Le Zénith'),
            new \DateTimeImmutable($startsAt),
            Capacity::of(42),
            [self::tier('Réduit')],
            self::now(),
        );
    }

    private static function reconstituted(EventStatus $status, bool $locked, string $startsAt = '2027-01-01T20:00:00+00:00'): Event
    {
        return Event::reconstitute(
            EventId::generate(),
            OrganizationId::generate(),
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            new \DateTimeImmutable($startsAt),
            Capacity::of(100),
            [self::tier('Plein')],
            $status,
            $locked,
        );
    }

    /**
     * @param list<PriceTier> $tiers
     */
    private static function draftWith(array $tiers): Event
    {
        return Event::draft(
            EventId::generate(),
            OrganizationId::generate(),
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            new \DateTimeImmutable('2027-01-01T20:00:00+00:00'),
            Capacity::of(100),
            $tiers,
            self::now(),
        );
    }

    private static function tier(string $label): PriceTier
    {
        return new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString($label), Money::EUR(1000));
    }
}
