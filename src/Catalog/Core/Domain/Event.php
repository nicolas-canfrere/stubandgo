<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventLockedException;
use App\Catalog\Core\Domain\Exception\EventNotDraftException;
use App\Catalog\Core\Domain\Exception\EventNotPublishedException;
use App\Catalog\Core\Domain\Exception\EventStartInPastException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Event\EventPublished;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\ValueObject\Capacity;

final class Event
{
    private EventStatus $status = EventStatus::Draft;
    private bool $locked = false;

    /**
     * @param list<PriceTier> $priceTiers
     */
    private function __construct(
        private readonly EventId $id,
        private readonly OrganizationId $organizationId,
        private EventTitle $title,
        private EventDescription $description,
        private Venue $venue,
        private \DateTimeImmutable $startsAt,
        private Capacity $capacity,
        private array $priceTiers,
    ) {
    }

    /**
     * @param list<PriceTier> $priceTiers
     */
    public static function draft(
        EventId $id,
        OrganizationId $organizationId,
        EventTitle $title,
        EventDescription $description,
        Venue $venue,
        \DateTimeImmutable $startsAt,
        Capacity $capacity,
        array $priceTiers,
        \DateTimeImmutable $now,
    ): self {
        self::assertStartsAfter($id, $startsAt, $now);
        self::assertDistinctTierIds($priceTiers);

        return new self($id, $organizationId, $title, $description, $venue, self::utc($startsAt), $capacity, $priceTiers);
    }

    /**
     * Rebuilds a persisted event: the draft-time checks already passed when it was first created.
     *
     * @param list<PriceTier> $priceTiers
     */
    public static function reconstitute(
        EventId $id,
        OrganizationId $organizationId,
        EventTitle $title,
        EventDescription $description,
        Venue $venue,
        \DateTimeImmutable $startsAt,
        Capacity $capacity,
        array $priceTiers,
        EventStatus $status,
        bool $locked,
    ): self {
        $event = new self($id, $organizationId, $title, $description, $venue, self::utc($startsAt), $capacity, $priceTiers);
        $event->status = $status;
        $event->locked = $locked;

        return $event;
    }

    /**
     * Replaces everything an organizer describes, the price tiers included: only an unlocked draft can be revised.
     *
     * @param list<PriceTier> $priceTiers
     */
    public function revise(
        EventTitle $title,
        EventDescription $description,
        Venue $venue,
        \DateTimeImmutable $startsAt,
        Capacity $capacity,
        array $priceTiers,
        \DateTimeImmutable $now,
    ): void {
        $this->assertUnlocked();

        if (EventStatus::Draft !== $this->status) {
            throw EventNotDraftException::withId($this->id);
        }

        self::assertStartsAfter($this->id, $startsAt, $now);
        self::assertDistinctTierIds($priceTiers);

        $this->title = $title;
        $this->description = $description;
        $this->venue = $venue;
        $this->startsAt = self::utc($startsAt);
        $this->capacity = $capacity;
        $this->priceTiers = $priceTiers;
    }

    public function publish(\DateTimeImmutable $now): EventPublished
    {
        if (EventStatus::Published === $this->status) {
            throw EventAlreadyPublishedException::withId($this->id);
        }

        if ([] === $this->priceTiers) {
            throw EventWithoutPriceTierException::withId($this->id);
        }

        self::assertStartsAfter($this->id, $this->startsAt, $now);
        $this->status = EventStatus::Published;

        return new EventPublished($this->id, $this->capacity);
    }

    /**
     * Takes an event off sale; refused once seats have been taken.
     */
    public function unpublish(): void
    {
        $this->assertUnlocked();

        if (EventStatus::Published !== $this->status) {
            throw EventNotPublishedException::withId($this->id);
        }

        $this->status = EventStatus::Draft;
    }

    public function lock(): void
    {
        $this->locked = true;
    }

    public function unlock(): void
    {
        $this->locked = false;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function id(): EventId
    {
        return $this->id;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function title(): EventTitle
    {
        return $this->title;
    }

    public function description(): EventDescription
    {
        return $this->description;
    }

    public function venue(): Venue
    {
        return $this->venue;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function capacity(): Capacity
    {
        return $this->capacity;
    }

    /**
     * @return list<PriceTier>
     */
    public function priceTiers(): array
    {
        return $this->priceTiers;
    }

    public function status(): EventStatus
    {
        return $this->status;
    }

    private function assertUnlocked(): void
    {
        if ($this->locked) {
            throw EventLockedException::withId($this->id);
        }
    }

    private static function assertStartsAfter(EventId $id, \DateTimeImmutable $startsAt, \DateTimeImmutable $now): void
    {
        if ($startsAt <= $now) {
            throw EventStartInPastException::withId($id);
        }
    }

    /**
     * @param list<PriceTier> $priceTiers
     */
    private static function assertDistinctTierIds(array $priceTiers): void
    {
        $ids = array_map(static fn(PriceTier $tier): string => $tier->id()->toString(), $priceTiers);

        if (\count(array_unique($ids)) !== \count($ids)) {
            throw new InvalidValueException('An event cannot have two price tiers with the same identifier.');
        }
    }

    private static function utc(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
