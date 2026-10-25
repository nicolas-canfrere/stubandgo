<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Command;

use App\Catalog\Core\Application\Command\ReviseEvent;
use App\Catalog\Core\Application\Command\UnpublishEvent;
use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\Exception\EventLockedException;
use App\Catalog\Core\Domain\Exception\EventNotDraftException;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\EventStartInPastException;
use App\Catalog\Core\Domain\PriceTier;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;

final class ReviseEventHandlerTest extends AbstractCatalogTestCase
{
    public function testReviseReplacesEveryFieldAndTheTiersOfADraft(): void
    {
        $organization = $this->createOrganization();
        [$kept, $removed, $modified, $added] = [PriceTierId::generate()->toString(), PriceTierId::generate()->toString(), PriceTierId::generate()->toString(), PriceTierId::generate()->toString()];
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', priceTiers: [
            ['id' => $kept, 'label' => 'Plein', 'priceCents' => 2500],
            ['id' => $removed, 'label' => 'Réduit', 'priceCents' => 1500],
            ['id' => $modified, 'label' => 'Gratuit', 'priceCents' => 0],
        ]);

        $this->command(new ReviseEvent($eventId, 'Nouveau titre', 'Nouvelle description', 'Le Zénith', '2027-02-01T21:00:00+01:00', 42, [
            ['id' => $modified, 'label' => 'Mécène', 'priceCents' => 9000],
            ['id' => $added, 'label' => 'Étudiant', 'priceCents' => 1000],
            ['id' => $kept, 'label' => 'Plein', 'priceCents' => 2500],
        ]));

        $event = $this->event($eventId);
        self::assertSame($organization['id'], $event->organizationId()->toString());
        self::assertSame('Nouveau titre', $event->title()->toString());
        self::assertSame('Nouvelle description', $event->description()->toString());
        self::assertSame('Le Zénith', $event->venue()->toString());
        self::assertSame('2027-02-01T20:00:00+00:00', $event->startsAt()->format(\DATE_ATOM));
        self::assertSame(42, $event->capacity()->toInt());
        self::assertSame(
            [[$modified, 'Mécène', '9000'], [$added, 'Étudiant', '1000'], [$kept, 'Plein', '2500']],
            array_map(static fn(PriceTier $tier): array => [$tier->id()->toString(), $tier->label()->toString(), $tier->price()->getAmount()], $event->priceTiers()),
        );
    }

    public function testReviseRefusesAPublishedEvent(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true);

        $this->expectException(EventNotDraftException::class);

        $this->revise($eventId);
    }

    public function testReviseRefusesALockedEvent(): void
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: [['id' => $tier, 'label' => 'Plein', 'priceCents' => 2500]]);
        $this->command(new PlaceOrder(OrderId::generate()->toString(), $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => 1]]));

        $this->expectException(EventLockedException::class);

        $this->revise($eventId);
    }

    public function testReviseRefusesAStartThatIsNotInTheFuture(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00');

        $this->expectException(EventStartInPastException::class);

        $this->revise($eventId, self::NOW);
    }

    public function testReviseRefusesAMalformedStart(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00');

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('ISO 8601');

        $this->revise($eventId, '2027-02-01');
    }

    public function testReviseUnknownEventFails(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->revise(EventId::generate()->toString());
    }

    public function testReviseRefusesATierOfAnotherEventAndChangesNothing(): void
    {
        $organization = $this->createOrganization();
        $foreign = PriceTierId::generate()->toString();
        $otherId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', priceTiers: [['id' => $foreign, 'label' => 'Autre', 'priceCents' => 700]]);
        $own = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', priceTiers: [['id' => $own, 'label' => 'Plein', 'priceCents' => 2500]]);

        try {
            $this->command(new ReviseEvent($eventId, 'Nouveau titre', 'Nouvelle description', 'Le Zénith', '2027-02-01T20:00:00+00:00', 42, [
                ['id' => PriceTierId::generate()->toString(), 'label' => 'Neuf', 'priceCents' => 100],
                ['id' => $foreign, 'label' => 'Volé', 'priceCents' => 1],
            ]));
            self::fail('A tier of another event must be refused.');
        } catch (InvalidValueException) {
            $event = $this->event($eventId);
            self::assertSame('Concert', $event->title()->toString());
            self::assertSame(100, $event->capacity()->toInt());
            self::assertSame([[$own, 'Plein']], array_map(static fn(PriceTier $tier): array => [$tier->id()->toString(), $tier->label()->toString()], $event->priceTiers()));
            self::assertSame([[$foreign, 'Autre', '700']], array_map(static fn(PriceTier $tier): array => [$tier->id()->toString(), $tier->label()->toString(), $tier->price()->getAmount()], $this->event($otherId)->priceTiers()));
        }
    }

    public function testAnUnpublishedEventCanBeRevised(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true);
        $this->command(new UnpublishEvent($eventId));

        $this->revise($eventId);

        self::assertSame('Nouveau titre', $this->event($eventId)->title()->toString());
    }

    public function testReviseCommandCarriesItsPayload(): void
    {
        $command = new ReviseEvent('a', 'b', 'c', 'd', 'e', 3, [['id' => 'f', 'label' => 'g', 'priceCents' => 4]]);

        self::assertSame(
            ['a', 'b', 'c', 'd', 'e', 3, [['id' => 'f', 'label' => 'g', 'priceCents' => 4]]],
            [$command->eventId, $command->title, $command->description, $command->venue, $command->startsAt, $command->capacity, $command->priceTiers],
        );
    }

    private function revise(string $eventId, string $startsAt = '2027-02-01T20:00:00+00:00'): void
    {
        $this->command(new ReviseEvent($eventId, 'Nouveau titre', 'Nouvelle description', 'Le Zénith', $startsAt, 42, [
            ['id' => PriceTierId::generate()->toString(), 'label' => 'Plein', 'priceCents' => 2500],
        ]));
    }

    private function event(string $eventId): Event
    {
        return self::getContainer()->get(EventRepositoryInterface::class)->get(EventId::fromString($eventId));
    }
}
