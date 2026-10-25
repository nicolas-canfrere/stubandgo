<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Http;

use App\Shared\Identity\EventId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\CatalogFixturesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CatalogApiTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
    }

    public function testEventsEndpointReturnsUpcomingEventsJson(): void
    {
        $organization = $this->createOrganization();
        $upcoming = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00', publish: true);
        $draft = $this->createEvent($organization['id'], '2026-12-02T20:00:00+01:00');
        $past = $this->createEvent($organization['id'], '2026-01-01T20:00:00+01:00', publish: true);

        $events = $this->getJson('/api/events');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertIsList($events);
        $byId = [];
        foreach ($events as $event) {
            self::assertIsArray($event);
            self::assertIsString($event['id']);
            $byId[$event['id']] = $event;
        }
        self::assertSame([
            'id' => $upcoming,
            'title' => 'Concert',
            'venue' => 'La Cigale',
            'startsAt' => '2026-12-01T19:00:00Z',
            'organization' => ['slug' => $organization['slug'], 'name' => 'Rock Asso'],
        ], $byId[$upcoming]);
        self::assertArrayNotHasKey($draft, $byId);
        self::assertArrayNotHasKey($past, $byId);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function startsAtConversions(): iterable
    {
        yield 'winter, UTC input' => ['2026-12-01T19:00:00+00:00', '2026-12-01T19:00:00Z'];
        yield 'winter, Paris input' => ['2026-12-01T20:00:00+01:00', '2026-12-01T19:00:00Z'];
        yield 'summer, UTC input' => ['2027-07-01T20:00:00Z', '2027-07-01T20:00:00Z'];
        yield 'summer, Paris input' => ['2027-07-01T22:00:00+02:00', '2027-07-01T20:00:00Z'];
        yield 'summer, New York input' => ['2027-07-01T16:00:00-04:00', '2027-07-01T20:00:00Z'];
        yield 'day change, Tokyo input' => ['2027-01-02T05:30:00+09:00', '2027-01-01T20:30:00Z'];
    }

    #[DataProvider('startsAtConversions')]
    public function testStartsAtIsExposedInUtcWhateverTheInputTimezone(string $stored, string $expected): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], $stored, publish: true);

        $detail = $this->getJson('/api/events/' . $eventId);
        $catalog = $this->getJson('/api/organizations/' . $organization['slug']);

        self::assertIsArray($detail);
        self::assertSame($expected, $detail['startsAt']);
        self::assertIsArray($catalog);
        self::assertIsArray($catalog['events']);
        self::assertIsArray($catalog['events'][0]);
        self::assertSame($expected, $catalog['events'][0]['startsAt']);
    }

    public function testOrganizationCatalogListsItsPublishedEvents(): void
    {
        $organization = $this->createOrganization();
        $second = $this->createEvent($organization['id'], '2026-12-02T20:00:00+01:00', publish: true, title: 'Second');
        $first = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00', publish: true, title: 'First');
        $this->createEvent($organization['id'], '2026-12-03T20:00:00+01:00');

        $catalog = $this->getJson('/api/organizations/' . $organization['slug']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'slug' => $organization['slug'],
            'name' => 'Rock Asso',
            'events' => [
                ['id' => $first, 'title' => 'First', 'venue' => 'La Cigale', 'startsAt' => '2026-12-01T19:00:00Z', 'organization' => ['slug' => $organization['slug'], 'name' => 'Rock Asso']],
                ['id' => $second, 'title' => 'Second', 'venue' => 'La Cigale', 'startsAt' => '2026-12-02T19:00:00Z', 'organization' => ['slug' => $organization['slug'], 'name' => 'Rock Asso']],
            ],
        ], $catalog);
    }

    public function testUnknownOrganizationReturns404WithErrorCode(): void
    {
        $slug = 'unknown-' . strtolower(EventId::generate()->toString());

        $problem = $this->getJson('/api/organizations/' . $slug);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([
            'type' => '/problems/organization_not_found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => \sprintf('Organization with slug "%s" not found.', $slug),
            'instance' => '/api/organizations/' . $slug,
        ], $problem);
    }

    public function testEventDetailListsPriceTiersWithCents(): void
    {
        $organization = $this->createOrganization();
        $plein = PriceTierId::generate()->toString();
        $gratuit = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00', publish: true, priceTiers: [
            ['id' => $plein, 'label' => 'Plein', 'priceCents' => 2550],
            ['id' => $gratuit, 'label' => 'Gratuit', 'priceCents' => 0],
        ]);

        $detail = $this->getJson('/api/events/' . $eventId);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'id' => $eventId,
            'title' => 'Concert',
            'description' => 'Un super concert',
            'venue' => 'La Cigale',
            'startsAt' => '2026-12-01T19:00:00Z',
            'capacity' => 100,
            'organization' => ['slug' => $organization['slug'], 'name' => 'Rock Asso'],
            'priceTiers' => [
                ['id' => $plein, 'label' => 'Plein', 'priceCents' => 2550],
                ['id' => $gratuit, 'label' => 'Gratuit', 'priceCents' => 0],
            ],
        ], $detail);
    }

    public function testDraftEventDetailReturns404(): void
    {
        $organization = $this->createOrganization();
        $eventId = $this->createEvent($organization['id'], '2026-12-01T20:00:00+01:00');

        $problem = $this->getJson('/api/events/' . $eventId);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/event_not_found', $problem['type']);
    }

    public function testMalformedEventIdReturns422WithErrorCode(): void
    {
        $problem = $this->getJson('/api/events/not-a-ulid');

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/invalid_value', $problem['type']);
        self::assertSame('/api/events/not-a-ulid', $problem['instance']);
    }

    public function testApiDocJsonListsCatalogPaths(): void
    {
        $doc = $this->getJson('/api/doc.json');

        self::assertResponseIsSuccessful();
        self::assertIsArray($doc);
        self::assertIsArray($doc['paths']);
        foreach (['/api/events', '/api/events/{eventId}', '/api/organizations/{slug}'] as $path) {
            self::assertArrayHasKey($path, $doc['paths']);
        }
        foreach (['/api/events/{eventId}', '/api/organizations/{slug}'] as $path) {
            self::assertIsArray($doc['paths'][$path]);
            self::assertIsArray($doc['paths'][$path]['get']);
            self::assertIsArray($doc['paths'][$path]['get']['responses']);
            self::assertArrayHasKey('200', $doc['paths'][$path]['get']['responses']);
            self::assertArrayHasKey('404', $doc['paths'][$path]['get']['responses']);
        }
    }

    public function testOrganizationReferenceSchemaRequiresSlugAndName(): void
    {
        $doc = $this->getJson('/api/doc.json');

        self::assertIsArray($doc);
        $required = $this->docNode($doc, 'components', 'schemas', 'OrganizationReference', 'required');
        self::assertIsArray($required);
        self::assertContains('slug', $required);
        self::assertContains('name', $required);
        self::assertSame('string', $this->docNode($doc, 'components', 'schemas', 'OrganizationReference', 'properties', 'name', 'type'));
    }

    public function testSwaggerUiIsServed(): void
    {
        $this->client->request('GET', '/api/doc');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#swagger-ui');
    }

    /**
     * @param array<mixed> $doc
     */
    private function docNode(array $doc, string ...$keys): mixed
    {
        $node = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    private function getJson(string $uri): mixed
    {
        $this->client->request('GET', $uri);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
