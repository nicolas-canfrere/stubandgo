<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\Capacity;
use Money\Money;
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

        $event = Event::draft($id, $organizationId, $title, $description, $venue, $startsAt, $capacity, $tiers);

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

        $published = $event->publish();

        self::assertSame($event->id(), $published->eventId);
        self::assertSame($event->capacity(), $published->capacity);
        self::assertSame(EventStatus::Published, $event->status());
    }

    public function testCannotPublishTwice(): void
    {
        $event = self::draftWith([self::tier('Plein')]);
        $event->publish();

        try {
            $event->publish();
            self::fail('Expected an exception.');
        } catch (EventAlreadyPublishedException $e) {
            self::assertSame('event_already_published', $e->errorCode());
        }
    }

    public function testCannotPublishWithoutPriceTier(): void
    {
        $event = self::draftWith([]);

        try {
            $event->publish();
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
            new \DateTimeImmutable('2027-01-01 20:00:00'),
            Capacity::of(100),
            $tiers,
        );
    }

    private static function tier(string $label): PriceTier
    {
        return new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString($label), Money::EUR(1000));
    }
}
