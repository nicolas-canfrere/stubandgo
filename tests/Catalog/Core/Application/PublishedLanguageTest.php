<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application;

use App\Catalog\Core\Application\Published\EventDisplay;
use App\Catalog\Core\Application\Published\EventDisplayProviderInterface;
use App\Catalog\Core\Application\Published\EventOrganizationProviderInterface;
use App\Catalog\Core\Application\Published\EventSaleTerms;
use App\Catalog\Core\Application\Published\EventSaleTermsProviderInterface;
use App\Catalog\Core\Application\Published\PriceTierTerms;
use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\AbstractCatalogTestCase;

final class PublishedLanguageTest extends AbstractCatalogTestCase
{
    public function testSaleTermsExposeTiersPricesAndCommission(): void
    {
        $organization = $this->createOrganization(750);
        $plein = PriceTierId::generate()->toString();
        $reduit = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00', publish: true, priceTiers: [
            ['id' => $plein, 'label' => 'Plein', 'priceCents' => 2550],
            ['id' => $reduit, 'label' => 'Réduit', 'priceCents' => 1200],
        ]);

        $terms = self::getContainer()->get(EventSaleTermsProviderInterface::class)->forEvent($eventId);

        self::assertEquals(new EventSaleTerms(
            $eventId,
            true,
            new \DateTimeImmutable('2026-12-01T19:00:00+00:00'),
            750,
            [new PriceTierTerms($plein, 2550), new PriceTierTerms($reduit, 1200)],
        ), $terms);
    }

    public function testSaleTermsOfAnUnpublishedEventAreFlaggedUnpublished(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00');

        $terms = self::getContainer()->get(EventSaleTermsProviderInterface::class)->forEvent($eventId);

        self::assertNotNull($terms);
        self::assertFalse($terms->published);
    }

    public function testSaleTermsReturnNullForUnknownEvent(): void
    {
        self::assertNull(self::getContainer()->get(EventSaleTermsProviderInterface::class)->forEvent(EventId::generate()->toString()));
    }

    public function testDisplayExposesTitleVenueStartAndTierLabels(): void
    {
        $plein = PriceTierId::generate()->toString();
        $reduit = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+01:00', priceTiers: [
            ['id' => $plein, 'label' => 'Plein', 'priceCents' => 2550],
            ['id' => $reduit, 'label' => 'Réduit', 'priceCents' => 1200],
        ]);

        $display = self::getContainer()->get(EventDisplayProviderInterface::class)->forEvent($eventId);

        self::assertEquals(new EventDisplay(
            'Concert',
            'La Cigale',
            new \DateTimeImmutable('2026-12-01T19:00:00+00:00'),
            [$plein => 'Plein', $reduit => 'Réduit'],
            100,
        ), $display);
    }

    public function testDisplayReturnsNullForUnknownEvent(): void
    {
        self::assertNull(self::getContainer()->get(EventDisplayProviderInterface::class)->forEvent(EventId::generate()->toString()));
    }

    public function testEventOrganizationIsTheKeycloakIdOfItsOrganization(): void
    {
        $organization = $this->createOrganization(keycloakOrganizationId: '0F8C4F8E-3C7A-4B52-9A51-6D1F2B7C0A01');
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00');

        self::assertSame('0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01', $this->eventOrganizations()->keycloakOrganizationIdOf($eventId));
    }

    public function testEventOrganizationIsNullWhenTheOrganizationHasNoKeycloakId(): void
    {
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+01:00');

        self::assertNull($this->eventOrganizations()->keycloakOrganizationIdOf($eventId));
    }

    public function testEventOrganizationIsNullForUnknownEvent(): void
    {
        self::assertNull($this->eventOrganizations()->keycloakOrganizationIdOf(EventId::generate()->toString()));
    }

    public function testEventOrganizationIsNullForAnIdentifierThatIsNotAUlid(): void
    {
        self::assertNull($this->eventOrganizations()->keycloakOrganizationIdOf('not-a-ulid'));
    }

    private function eventOrganizations(): EventOrganizationProviderInterface
    {
        return self::getContainer()->get(EventOrganizationProviderInterface::class);
    }
}
