<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure;

use App\Catalog\Core\Domain\Event;
use App\Catalog\Core\Domain\EventRepositoryInterface;
use App\Catalog\Core\Domain\EventStatus;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\Organization;
use App\Catalog\Core\Domain\OrganizationRepositoryInterface;
use App\Catalog\Core\Domain\OrganizationStatus;
use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\CommissionRate;
use App\Catalog\Core\Domain\ValueObject\EventDescription;
use App\Catalog\Core\Domain\ValueObject\EventTitle;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Catalog\Core\Domain\ValueObject\Venue;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\Capacity;
use Doctrine\DBAL\Connection;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalRepositoriesTest extends KernelTestCase
{
    private EventRepositoryInterface $events;
    private OrganizationRepositoryInterface $organizations;

    protected function setUp(): void
    {
        $this->events = self::getContainer()->get(EventRepositoryInterface::class);
        $this->organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);
    }

    public function testRepositoryRoundTripPreservesMoneyAndUtcDate(): void
    {
        $organization = $this->organization();
        $startsAt = new \DateTimeImmutable('2026-12-31T23:30:15.123456+05:00');
        $tiers = [
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Plein'), Money::EUR(2550)),
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Mécène'), Money::EUR(5_000_000_001)),
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Gratuit'), Money::EUR(0)),
        ];
        $event = $this->event($organization->id(), $startsAt, $tiers);

        $this->events->save($event);
        $loaded = $this->events->get($event->id());

        self::assertTrue($event->id()->equals($loaded->id()));
        self::assertTrue($organization->id()->equals($loaded->organizationId()));
        self::assertSame('Concert', $loaded->title()->toString());
        self::assertSame('Un super concert', $loaded->description()->toString());
        self::assertSame('La Cigale', $loaded->venue()->toString());
        self::assertSame(100, $loaded->capacity()->toInt());
        self::assertSame('2026-12-31T18:30:15.123456+00:00', $loaded->startsAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('UTC', $loaded->startsAt()->getTimezone()->getName());
        self::assertSame(EventStatus::Draft, $loaded->status());
        self::assertFalse($loaded->isLocked());
        self::assertCount(3, $loaded->priceTiers());
        foreach ($tiers as $position => $tier) {
            self::assertTrue($tier->id()->equals($loaded->priceTiers()[$position]->id()));
            self::assertSame($tier->label()->toString(), $loaded->priceTiers()[$position]->label()->toString());
            self::assertTrue($tier->price()->equals($loaded->priceTiers()[$position]->price()));
        }
    }

    public function testSavingAgainPersistsStatusAndLockButKeepsTheTiers(): void
    {
        $organization = $this->organization();
        $event = $this->event($organization->id(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), [
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Plein'), Money::EUR(1000)),
        ]);
        $this->events->save($event);

        $event->publish();
        $event->lock();
        $this->events->save($event);
        $loaded = $this->events->get($event->id());

        self::assertSame(EventStatus::Published, $loaded->status());
        self::assertTrue($loaded->isLocked());
        self::assertCount(1, $loaded->priceTiers());

        $event->unlock();
        $this->events->save($event);

        self::assertFalse($this->events->get($event->id())->isLocked());
        self::assertCount(1, $this->events->get($event->id())->priceTiers());
    }

    public function testFindReturnsNullAndGetThrowsForUnknownEvent(): void
    {
        $id = EventId::generate();

        self::assertNull($this->events->find($id));

        $this->expectException(EventNotFoundException::class);
        $this->events->get($id);
    }

    public function testFindReturnsTheEvent(): void
    {
        $event = $this->event($this->organization()->id(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), []);
        $this->events->save($event);

        $found = $this->events->find($event->id());

        self::assertNotNull($found);
        self::assertTrue($event->id()->equals($found->id()));
        self::assertSame([], $found->priceTiers());
    }

    public function testListPublishedStartingAfterIsStrictPublishedOnlyAndOrdered(): void
    {
        $organization = $this->organization();
        $now = new \DateTimeImmutable('2026-10-24T12:00:00+00:00');
        $second = $this->published($organization->id(), '2026-12-02T20:00:00+00:00');
        $first = $this->published($organization->id(), '2026-12-01T20:00:00+00:00');
        $past = $this->published($organization->id(), '2026-10-24T12:00:00+00:00');
        $draft = $this->event($organization->id(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), []);
        $this->events->save($draft);

        $ids = array_map(static fn(Event $event): string => $event->id()->toString(), $this->events->listPublishedStartingAfter($now));
        $mine = array_values(array_intersect($ids, [$first->toString(), $second->toString(), $past->toString(), $draft->id()->toString()]));

        self::assertSame([$first->toString(), $second->toString()], $mine);
    }

    public function testListPublishedStartingAfterLoadsTiersOfEachEvent(): void
    {
        $organization = $this->organization();
        $one = $this->published($organization->id(), '2026-12-01T20:00:00+00:00', 'Alpha');
        $two = $this->published($organization->id(), '2026-12-02T20:00:00+00:00', 'Beta');

        $events = array_filter(
            $this->events->listPublishedStartingAfter(new \DateTimeImmutable('2026-10-24T12:00:00+00:00')),
            static fn(Event $event): bool => $event->id()->equals($one) || $event->id()->equals($two),
        );

        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertCount(1, $event->priceTiers());
            self::assertSame($event->id()->equals($one) ? 'Alpha' : 'Beta', $event->priceTiers()[0]->label()->toString());
        }
    }

    public function testListPublishedByOrganizationIsScopedUpcomingPublishedOnlyAndOrdered(): void
    {
        $organization = $this->organization();
        $other = $this->organization();
        $now = new \DateTimeImmutable('2026-10-24T12:00:00+00:00');
        $second = $this->published($organization->id(), '2026-12-02T20:00:00+00:00');
        $first = $this->published($organization->id(), '2026-10-24T12:00:01+00:00');
        $this->published($organization->id(), '2026-10-24T12:00:00+00:00');
        $this->published($organization->id(), '2025-01-01T20:00:00+00:00');
        $this->published($other->id(), '2026-12-01T20:00:00+00:00');
        $this->events->save($this->event($organization->id(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), []));

        $ids = array_map(static fn(Event $event): string => $event->id()->toString(), $this->events->listPublishedByOrganization($organization->id(), $now));

        self::assertSame([$first->toString(), $second->toString()], $ids);
    }

    public function testListsAreEmptyWithoutMatches(): void
    {
        self::assertSame([], $this->events->listPublishedByOrganization(OrganizationId::generate(), new \DateTimeImmutable('2026-10-24T12:00:00+00:00')));
    }

    public function testOrganizationRoundTrip(): void
    {
        $organization = $this->organization(1234);

        foreach ([$this->organizations->get($organization->id()), $this->organizations->getBySlug($organization->slug()), $this->organizations->find($organization->id())] as $loaded) {
            self::assertNotNull($loaded);
            self::assertTrue($organization->id()->equals($loaded->id()));
            self::assertSame('Rock Asso ' . $organization->id()->toString(), $loaded->name()->toString());
            self::assertTrue($organization->slug()->equals($loaded->slug()));
            self::assertSame(1234, $loaded->commissionRate()->toBasisPoints());
        }
    }

    public function testUnknownOrganization(): void
    {
        $id = OrganizationId::generate();

        self::assertNull($this->organizations->find($id));

        $this->expectException(OrganizationNotFoundException::class);
        $this->organizations->get($id);
    }

    public function testUnknownSlug(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->organizations->getBySlug(Slug::fromString('nope-' . strtolower((string) OrganizationId::generate()->toString())));
    }

    public function testSlugIsUnique(): void
    {
        $organization = $this->organization();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->organizations->save(Organization::create(OrganizationId::generate(), OrganizationName::fromString('Autre'), $organization->slug(), CommissionRate::fromBasisPoints(0)));
    }

    public function testKeycloakIdRoundTrips(): void
    {
        $withId = $this->organization(keycloakId: '0F8C4F8E-3C7A-4B52-9A51-6D1F2B7C0AFF');
        $without = $this->organization();

        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0aff', $this->organizations->get($withId->id())->keycloakId()?->toString());
        self::assertNull($this->organizations->get($without->id())->keycloakId());
        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0aff', $this->organizations->getBySlug($withId->slug())->keycloakId()?->toString());
    }

    public function testKeycloakIdIsUnique(): void
    {
        $this->organization(keycloakId: '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0afe');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->organization(keycloakId: '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0afe');
    }

    public function testNameIsUnique(): void
    {
        $organization = $this->organization();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->organizations->save(Organization::create(OrganizationId::generate(), $organization->name(), Slug::fromString('other-' . strtolower(OrganizationId::generate()->toString())), CommissionRate::fromBasisPoints(0)));
    }

    public function testStatusRoundTrips(): void
    {
        $pending = $this->organization(keycloakId: null);
        $active = $this->organization(keycloakId: '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a77');

        self::assertSame(OrganizationStatus::PendingKeycloak, $this->organizations->get($pending->id())->status());
        self::assertSame(OrganizationStatus::Active, $this->organizations->get($active->id())->status());
        self::assertSame(OrganizationStatus::Active, $this->organizations->getBySlug($active->slug())->status());
    }

    public function testSavingAgainUpdatesStatusKeycloakIdAndRateOnly(): void
    {
        $organization = $this->organization(commission: 1000, keycloakId: null);
        $organization->activate(KeycloakOrganizationId::fromString('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a78'));
        $organization->changeCommissionRate(CommissionRate::fromBasisPoints(42));

        $this->organizations->save($organization);

        $loaded = $this->organizations->get($organization->id());
        self::assertSame(OrganizationStatus::Active, $loaded->status());
        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a78', $loaded->keycloakId()?->toString());
        self::assertSame(42, $loaded->commissionRate()->toBasisPoints());
        self::assertSame('Rock Asso ' . $organization->id()->toString(), $loaded->name()->toString());
    }

    public function testFindBySlug(): void
    {
        $organization = $this->organization();

        self::assertSame($organization->id()->toString(), $this->organizations->findBySlug($organization->slug())?->id()->toString());
        self::assertNull($this->organizations->findBySlug(Slug::fromString('nope-' . strtolower(OrganizationId::generate()->toString()))));
    }

    public function testNameExists(): void
    {
        $organization = $this->organization();

        self::assertTrue($this->organizations->nameExists($organization->name()));
        self::assertFalse($this->organizations->nameExists(OrganizationName::fromString('Nobody ' . OrganizationId::generate()->toString())));
    }

    #[DataProvider('inconsistentStatuses')]
    public function testStatusAndKeycloakIdMustAgree(string $status, ?string $keycloakId): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessage('SQLSTATE[23514]');

        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO organization (id, name, slug, commission_basis_points, status, keycloak_id) VALUES (?, ?, ?, 0, ?, ?)',
            [OrganizationId::generate()->toString(), 'Broken', 'broken-' . strtolower(OrganizationId::generate()->toString()), $status, $keycloakId],
        );
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function inconsistentStatuses(): iterable
    {
        yield 'active without keycloak id' => ['active', null];
        yield 'pending with keycloak id' => ['pending_keycloak', '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a79'];
        yield 'unknown status' => ['archived', null];
    }

    public function testEventRequiresAnExistingOrganization(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->events->save($this->event(OrganizationId::generate(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), []));
    }

    public function testDatabaseStoresMoneyInCentsAndDatesAsTimestamptz(): void
    {
        $organization = $this->organization();
        $event = $this->event($organization->id(), new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), [
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString('Plein'), Money::EUR(2550)),
        ]);
        $this->events->save($event);
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(2550, $connection->fetchOne('SELECT price_cents FROM price_tier WHERE event_id = ?', [$event->id()->toString()]));
        self::assertSame('timestamp with time zone', $connection->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'event' AND column_name = 'starts_at'"));
    }

    private function organization(int $commission = 1000, ?string $keycloakId = null): Organization
    {
        $id = OrganizationId::generate();
        $organization = Organization::create($id, OrganizationName::fromString('Rock Asso ' . $id->toString()), Slug::fromString('org-' . strtolower($id->toString())), CommissionRate::fromBasisPoints($commission), null === $keycloakId ? null : KeycloakOrganizationId::fromString($keycloakId));
        $this->organizations->save($organization);

        return $organization;
    }

    /**
     * @param list<PriceTier> $tiers
     */
    private function event(OrganizationId $organizationId, \DateTimeImmutable $startsAt, array $tiers): Event
    {
        return Event::draft(
            EventId::generate(),
            $organizationId,
            EventTitle::fromString('Concert'),
            EventDescription::fromString('Un super concert'),
            Venue::fromString('La Cigale'),
            $startsAt,
            Capacity::of(100),
            $tiers,
        );
    }

    private function published(OrganizationId $organizationId, string $startsAt, string $label = 'Plein'): EventId
    {
        $event = $this->event($organizationId, new \DateTimeImmutable($startsAt), [
            new PriceTier(PriceTierId::generate(), PriceTierLabel::fromString($label), Money::EUR(1000)),
        ]);
        $event->publish();
        $this->events->save($event);

        return $event->id();
    }
}
