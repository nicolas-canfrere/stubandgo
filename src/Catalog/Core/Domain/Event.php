<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
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
        private readonly EventTitle $title,
        private readonly EventDescription $description,
        private readonly Venue $venue,
        private readonly \DateTimeImmutable $startsAt,
        private readonly Capacity $capacity,
        private readonly array $priceTiers,
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
    ): self {
        $ids = array_map(static fn(PriceTier $tier): string => $tier->id()->toString(), $priceTiers);

        if (\count(array_unique($ids)) !== \count($ids)) {
            throw new InvalidValueException('An event cannot have two price tiers with the same identifier.');
        }

        return new self($id, $organizationId, $title, $description, $venue, $startsAt->setTimezone(new \DateTimeZone('UTC')), $capacity, $priceTiers);
    }

    public function publish(): EventPublished
    {
        if (EventStatus::Published === $this->status) {
            throw EventAlreadyPublishedException::withId($this->id);
        }

        if ([] === $this->priceTiers) {
            throw EventWithoutPriceTierException::withId($this->id);
        }

        $this->status = EventStatus::Published;

        return new EventPublished($this->id, $this->capacity);
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
}
