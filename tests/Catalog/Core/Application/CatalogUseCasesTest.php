<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application;

use App\Catalog\Core\Application\Command\CreateEvent;
use App\Catalog\Core\Application\Command\CreateOrganization;
use App\Catalog\Core\Application\Command\PublishEvent;
use App\Catalog\Core\Application\Query\EventSummaryView;
use App\Catalog\Core\Application\Query\GetEventDetail;
use App\Catalog\Core\Application\Query\GetOrganizationCatalog;
use App\Catalog\Core\Application\Query\ListUpcomingEvents;
use App\Catalog\Core\Application\Query\OrganizationCatalogView;
use App\Catalog\Core\Application\Query\PriceTierView;
use App\Catalog\Core\Domain\Exception\EventAlreadyPublishedException;
use App\Catalog\Core\Domain\Exception\EventNotFoundException;
use App\Catalog\Core\Domain\Exception\EventWithoutPriceTierException;
use App\Catalog\Core\Domain\Exception\OrganizationNameTakenException;
use App\Catalog\Core\Domain\Exception\OrganizationNotFoundException;
use App\Catalog\Core\Domain\Exception\OrganizationSlugTakenException;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;
use App\Tests\Catalog\Core\Application\Fixtures\EventPublishedRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogUseCasesTest extends AbstractCatalogTestCase
{
    public function testCreatedEventIsDraftAndNotListed(): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00');

        self::assertSame([], $this->idsOf($this->listUpcoming(), [$eventId]));
        self::assertSame([], $this->catalogOf($organization['slug'])->events);
        self::assertSame('draft', self::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM event WHERE id = ?', [$eventId]));
    }

    public function testPublishedUpcomingEventsAreListedByStartDate(): void
    {
        $organization = $this->createOrganization();
        $late = $this->createEvent($organization['id'], '2026-12-03T20:00:00+00:00', publish: true, title: 'Late');
        $early = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true, title: 'Early');
        $middle = $this->createEvent($organization['id'], '2026-12-02T20:00:00+00:00', publish: true, title: 'Middle');

        $listed = array_values(array_filter($this->listUpcoming(), static fn(EventSummaryView $view): bool => \in_array($view->eventId, [$late, $early, $middle], true)));

        self::assertSame([$early, $middle, $late], array_map(static fn(EventSummaryView $view): string => $view->eventId, $listed));
        self::assertSame($organization['slug'], $listed[0]->organizationSlug);
        self::assertSame($organization['name'], $listed[0]->organizationName);
        self::assertSame('Early', $listed[0]->title);
        self::assertSame('La Cigale', $listed[0]->venue);
        self::assertEquals(new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), $listed[0]->startsAt);
    }

    public function testEventsWithTheSameStartAreOrderedById(): void
    {
        $organization = $this->createOrganization();
        $ids = [
            $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true),
            $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true),
            $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true),
        ];
        sort($ids);

        self::assertSame($ids, $this->idsOf($this->listUpcoming(), $ids));
        self::assertSame($ids, array_map(static fn(EventSummaryView $view): string => $view->eventId, $this->catalogOf($organization['slug'])->events));
    }

    public function testPastEventsAreNotListed(): void
    {
        $organization = $this->createOrganization();
        $past = $this->createEvent($organization['id'], '2026-10-24T11:59:59+00:00', publish: true);
        $now = $this->createEvent($organization['id'], self::NOW, publish: true);
        $future = $this->createEvent($organization['id'], '2026-10-24T12:00:01+00:00', publish: true);

        self::assertSame([$future], $this->idsOf($this->listUpcoming(), [$past, $now, $future]));
        self::assertSame([$future], array_map(static fn(EventSummaryView $view): string => $view->eventId, $this->catalogOf($organization['slug'])->events));
    }

    public function testPublishEmitsEventPublished(): void
    {
        $recorder = self::getContainer()->get(EventPublishedRecorder::class);
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00');

        $this->command(new PublishEvent($eventId));

        self::assertCount(1, $recorder->recorded());
        self::assertSame($eventId, $recorder->recorded()[0]->eventId->toString());
        self::assertSame(100, $recorder->recorded()[0]->capacity->toInt());
    }

    public function testPublishingTwiceFailsAndEmitsNothingMore(): void
    {
        $recorder = self::getContainer()->get(EventPublishedRecorder::class);
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true);

        try {
            $this->command(new PublishEvent($eventId));
            self::fail('Publishing twice should fail.');
        } catch (EventAlreadyPublishedException) {
            self::assertCount(1, $recorder->recorded());
        }
    }

    public function testPublishUnknownEventThrowsEventNotFound(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->command(new PublishEvent(EventId::generate()->toString()));
    }

    public function testOrganizationCatalogListsOnlyItsUpcomingPublishedEvents(): void
    {
        $organization = $this->createOrganization();
        $other = $this->createOrganization();
        $published = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', publish: true);
        $this->createEvent($organization['id'], '2026-01-01T20:00:00+00:00', publish: true);
        $this->createEvent($organization['id'], '2026-12-02T20:00:00+00:00');
        $this->createEvent($other['id'], '2026-12-03T20:00:00+00:00', publish: true);

        $catalog = $this->catalogOf($organization['slug']);

        self::assertSame($organization['id'], $catalog->organizationId);
        self::assertSame($organization['name'], $catalog->name);
        self::assertSame($organization['slug'], $catalog->slug);
        self::assertSame([$published], array_map(static fn(EventSummaryView $view): string => $view->eventId, $catalog->events));
        self::assertSame([$organization['slug']], array_map(static fn(EventSummaryView $view): string => $view->organizationSlug, $catalog->events));
        self::assertSame([$organization['name']], array_map(static fn(EventSummaryView $view): string => $view->organizationName, $catalog->events));
    }

    public function testUnknownSlugThrowsOrganizationNotFound(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->ask(new GetOrganizationCatalog('unknown-' . strtolower(OrganizationId::generate()->toString())));
    }

    public function testEventDetailExposesTiersInDeclaredOrder(): void
    {
        $organization = $this->createOrganization();
        $second = PriceTierId::generate()->toString();
        $first = PriceTierId::generate()->toString();
        $third = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00', publish: true, priceTiers: [
            ['id' => $second, 'label' => 'Plein', 'priceCents' => 2500],
            ['id' => $first, 'label' => 'Réduit', 'priceCents' => 1500],
            ['id' => $third, 'label' => 'Gratuit', 'priceCents' => 0],
        ]);

        $detail = $this->ask(new GetEventDetail($eventId));

        self::assertSame($eventId, $detail->eventId);
        self::assertSame($organization['id'], $detail->organizationId);
        self::assertSame($organization['slug'], $detail->organizationSlug);
        self::assertSame($organization['name'], $detail->organizationName);
        self::assertSame('Concert', $detail->title);
        self::assertSame('Un super concert', $detail->description);
        self::assertSame('La Cigale', $detail->venue);
        self::assertEquals(new \DateTimeImmutable('2026-12-01T19:00:00+00:00'), $detail->startsAt);
        self::assertSame(100, $detail->capacity);
        self::assertEquals([
            new PriceTierView($second, 'Plein', 2500),
            new PriceTierView($first, 'Réduit', 1500),
            new PriceTierView($third, 'Gratuit', 0),
        ], $detail->priceTiers);
    }

    public function testEventDetailOfUnpublishedEventThrowsEventNotFound(): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00');

        $this->expectException(EventNotFoundException::class);

        $this->ask(new GetEventDetail($eventId));
    }

    public function testEventDetailOfUnknownEventThrowsEventNotFound(): void
    {
        $this->expectException(EventNotFoundException::class);

        $this->ask(new GetEventDetail(EventId::generate()->toString()));
    }

    public function testCreateEventForUnknownOrganizationThrowsOrganizationNotFound(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->createEvent(OrganizationId::generate()->toString(), '2026-12-01T20:00:00+00:00');
    }

    public function testCreateEventWithoutPriceTierCannotBePublished(): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+00:00', priceTiers: []);

        $this->expectException(EventWithoutPriceTierException::class);

        $this->command(new PublishEvent($eventId));
    }

    #[DataProvider('invalidStarts')]
    public function testCreateEventRejectsAnInvalidStart(string $startsAt): void
    {
        $organization = $this->createOrganization();

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('ISO 8601');

        $this->createEvent($organization['id'], $startsAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStarts(): iterable
    {
        yield 'text' => ['tomorrow'];
        yield 'date only' => ['2026-12-01'];
        yield 'impossible day' => ['2026-02-30T20:00:00+00:00'];
        yield 'trailing data' => ['2026-12-01T20:00:00+00:00 and more'];
    }

    public function testCreateEventAcceptsZuluSuffix(): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00Z', publish: true);

        $detail = $this->ask(new GetEventDetail($eventId));

        self::assertEquals(new \DateTimeImmutable('2026-12-01T20:00:00+00:00'), $detail->startsAt);
    }

    public function testCreateOrganizationRejectsInvalidCommission(): void
    {
        $id = OrganizationId::generate()->toString();

        $this->expectException(InvalidValueException::class);

        $this->command(new CreateOrganization($id, 'Rock Asso', 'org-' . strtolower($id), 10001));
    }

    public function testCreateOrganizationStoresItsKeycloakId(): void
    {
        $id = OrganizationId::generate()->toString();
        $this->command(new CreateOrganization($id, 'Rock Asso', 'org-' . strtolower($id), 1000, '0F8C4F8E-3C7A-4B52-9A51-6D1F2B7C0AAA'));

        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0aaa', self::getContainer()->get(Connection::class)->fetchOne('SELECT keycloak_id FROM organization WHERE id = ?', [$id]));
    }

    public function testCreateOrganizationWithoutKeycloakIdIsPending(): void
    {
        $organization = $this->createOrganization();

        self::assertSame('pending_keycloak', self::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM organization WHERE id = ?', [$organization['id']]));
    }

    public function testCreateOrganizationWithKeycloakIdIsActive(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0bb1');

        self::assertSame('active', self::getContainer()->get(Connection::class)->fetchOne('SELECT status FROM organization WHERE id = ?', [$organization['id']]));
    }

    public function testSlugAlreadyTakenIsAConflict(): void
    {
        $existing = $this->createOrganization();

        $this->expectException(OrganizationSlugTakenException::class);

        $this->command(new CreateOrganization(OrganizationId::generate()->toString(), 'Autre nom ' . $existing['id'], $existing['slug'], 1000));
    }

    public function testNameAlreadyTakenIsAConflict(): void
    {
        $existing = $this->createOrganization();
        $id = OrganizationId::generate()->toString();

        try {
            $this->command(new CreateOrganization($id, $existing['name'], 'org-' . strtolower($id), 1000));
            self::fail('The duplicate name must be rejected.');
        } catch (OrganizationNameTakenException $e) {
            self::assertSame('organization_name_taken', $e->errorCode());
        }
    }

    public function testCreateOrganizationRejectsMalformedKeycloakId(): void
    {
        $id = OrganizationId::generate()->toString();

        $this->expectException(InvalidValueException::class);

        $this->command(new CreateOrganization($id, 'Rock Asso', 'org-' . strtolower($id), 1000, 'not-a-uuid'));
    }

    public function testCreateEventCommandCarriesItsPayload(): void
    {
        $command = new CreateEvent('a', 'b', 'c', 'd', 'e', 'f', 3, []);

        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f', 3, []], [$command->id, $command->organizationId, $command->title, $command->description, $command->venue, $command->startsAt, $command->capacity, $command->priceTiers]);
    }

    /**
     * @return list<EventSummaryView>
     */
    private function listUpcoming(): array
    {
        $views = $this->ask(new ListUpcomingEvents());
        self::assertContainsOnlyInstancesOf(EventSummaryView::class, $views);

        return $views;
    }

    /**
     * @param list<EventSummaryView> $views
     * @param list<string>           $ids
     *
     * @return list<string> the given ids that appear in $views, in the order of $views
     */
    private function idsOf(array $views, array $ids): array
    {
        $listed = array_map(static fn(EventSummaryView $view): string => $view->eventId, $views);

        return array_values(array_filter($listed, static fn(string $id): bool => \in_array($id, $ids, true)));
    }

    private function catalogOf(string $slug): OrganizationCatalogView
    {
        $catalog = $this->ask(new GetOrganizationCatalog($slug));

        return $catalog;
    }
}
