<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
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

final readonly class CreateEventHandler
{
    public function __construct(
        private EventRepositoryInterface $events,
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function __invoke(CreateEvent $command): void
    {
        $organizationId = OrganizationId::fromString($command->organizationId);
        $this->organizations->get($organizationId);

        $this->events->save(Event::draft(
            EventId::fromString($command->id),
            $organizationId,
            EventTitle::fromString($command->title),
            EventDescription::fromString($command->description),
            Venue::fromString($command->venue),
            $this->parseStart($command->startsAt),
            Capacity::of($command->capacity),
            array_map(
                static fn(array $tier): PriceTier => new PriceTier(
                    PriceTierId::fromString($tier['id']),
                    PriceTierLabel::fromString($tier['label']),
                    Money::EUR($tier['priceCents']),
                ),
                $command->priceTiers,
            ),
        ));
    }

    private function parseStart(string $startsAt): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $startsAt);

        if (false === $date || false !== \DateTimeImmutable::getLastErrors()) {
            throw new InvalidValueException('Event start must be an ISO 8601 date-time with a UTC offset.');
        }

        return $date;
    }
}
