<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Http;

use App\Catalog\Core\Application\Query\GetBackofficeEvent;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Support\Security\TestTokenIssuer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class BackofficeEventApiTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    private const string EVENTS = '/api/backoffice/events';
    private const string OTHER_KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a99';
    private const string FUTURE = '2026-12-01T20:00:00+01:00';

    private KernelBrowser $client;

    /** @var array{id: string, slug: string, name: string} */
    private array $organization;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->organization = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        $id = '01JAE5Z8Q3M4N6P7R8S9T0V1W2';
        yield 'list' => ['GET', self::EVENTS];
        yield 'create' => ['POST', self::EVENTS];
        yield 'detail' => ['GET', self::EVENTS . '/' . $id];
        yield 'revise' => ['PUT', self::EVENTS . '/' . $id];
        yield 'publish' => ['POST', self::EVENTS . '/' . $id . '/publication'];
        yield 'unpublish' => ['DELETE', self::EVENTS . '/' . $id . '/publication'];
    }

    #[DataProvider('routes')]
    public function testEveryRouteRequiresAnAccessToken(string $method, string $uri): void
    {
        $problem = $this->call($method, $uri, null, $this->payload());

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('/problems/unauthorized', $problem['type'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonAdministrators(): iterable
    {
        yield 'controller' => [['realm_access' => ['roles' => ['organization-controller']]]];
        yield 'member without any role' => [['realm_access' => null]];
        yield 'administrator of a Keycloak organization unknown to stubandgo' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => ['autre' => ['id' => self::OTHER_KEYCLOAK_ID]]]];
        yield 'administrator without organization' => [['realm_access' => ['roles' => ['organization-admin']], 'organization' => null]];
        yield 'platform administrator' => [['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => ['roles' => ['platform-admin']], 'organization' => null]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('nonAdministrators')]
    public function testNoRouteIsOpenToWhoIsNotAnAdministratorOfTheOrganization(array $claims): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);
        $token = TestTokenIssuer::issue($claims);

        foreach ($this->routeSet($eventId) as [$method, $uri]) {
            $problem = $this->call($method, $uri, $token, $this->payload());

            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('/problems/forbidden', $problem['type'] ?? null, $method . ' ' . $uri);
        }
        self::assertSame('draft', $this->detailOf($eventId)['status']);
    }

    public function testTheRoutesOfAnEventAreForbiddenToTheAdministratorOfAnotherOrganization(): void
    {
        $other = $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $foreignEvent = $this->createEvent($other['id'], self::FUTURE);

        foreach ($this->eventRoutes($foreignEvent) as [$method, $uri]) {
            $problem = $this->call($method, $uri, $this->adminToken(), $this->payload());

            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('/problems/forbidden', $problem['type'] ?? null, $method . ' ' . $uri);
        }
        $event = $this->ask(new GetBackofficeEvent($foreignEvent));
        self::assertSame('draft', $event->status);
        self::assertSame('Concert', $event->title);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableEventIds(): iterable
    {
        yield 'unknown ULID' => ['01JAE5Z8Q3M4N6P7R8S9T0V1W2'];
        yield 'malformed identifier' => ['not-a-ulid'];
    }

    #[DataProvider('unreachableEventIds')]
    public function testAnUnknownOrMalformedEventIsForbiddenNotMissingNorUnprocessable(string $eventId): void
    {
        foreach ($this->eventRoutes($eventId) as [$method, $uri]) {
            $problem = $this->call($method, $uri, $this->adminToken(), $this->payload());

            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('/problems/forbidden', $problem['type'] ?? null, $method . ' ' . $uri);
        }
    }

    public function testAControllerWithAnInvalidPayloadIsForbiddenNotUnprocessable(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);
        $controller = TestTokenIssuer::issue();

        foreach ([['POST', self::EVENTS], ['PUT', self::EVENTS . '/' . $eventId]] as [$method, $uri]) {
            $problem = $this->call($method, $uri, $controller, ['title' => 42]);

            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('/problems/forbidden', $problem['type'] ?? null, $method . ' ' . $uri);
        }
    }

    public function testAnAdministratorOfAnotherOrganizationWithAnInvalidPayloadIsForbidden(): void
    {
        $other = $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $foreignEvent = $this->createEvent($other['id'], self::FUTURE);

        $problem = $this->call('PUT', self::EVENTS . '/' . $foreignEvent, $this->adminToken(), ['title' => 42]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
    }

    public function testAnOrganizationWithoutEventListsNone(): void
    {
        $body = $this->call('GET', self::EVENTS, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['events' => []], $body);
    }

    public function testTheListHoldsEveryEventOfTheOrganizationOfTheCallerOnly(): void
    {
        $other = $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $this->createEvent($other['id'], self::FUTURE, title: 'Chez les autres');
        $late = $this->createEvent($this->organization['id'], '2026-12-05T20:00:00+01:00', title: 'Tard');
        $published = $this->createEvent($this->organization['id'], '2026-12-01T20:00:00+01:00', publish: true, title: 'Tôt');

        $body = $this->call('GET', self::EVENTS, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['events' => [
            ['id' => $published, 'title' => 'Tôt', 'startsAt' => '2026-12-01T19:00:00Z', 'status' => 'published', 'locked' => false],
            ['id' => $late, 'title' => 'Tard', 'startsAt' => '2026-12-05T19:00:00Z', 'status' => 'draft', 'locked' => false],
        ]], $body);
    }

    public function testCreatingAnEventMakesAnInvisibleDraftOfTheOrganizationOfTheCaller(): void
    {
        $body = $this->call('POST', self::EVENTS, $this->adminToken(), $this->payload());

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($body['id'] ?? null);
        self::assertResponseHeaderSame('Location', self::EVENTS . '/' . $body['id']);
        $tiers = $body['priceTiers'] ?? null;
        self::assertIsArray($tiers);
        self::assertIsArray($tiers[0] ?? null);
        self::assertIsArray($tiers[1] ?? null);
        self::assertIsString($tiers[0]['id'] ?? null);
        self::assertSame([
            'id' => $body['id'],
            'title' => 'Soirée Rock',
            'startsAt' => '2026-12-01T19:00:00Z',
            'status' => 'draft',
            'locked' => false,
            'description' => 'Une belle soirée',
            'venue' => 'La Cigale',
            'capacity' => 150,
            'priceTiers' => [
                ['id' => $tiers[0]['id'], 'label' => 'Plein', 'priceCents' => 2500],
                ['id' => $tiers[1]['id'] ?? null, 'label' => 'Réduit', 'priceCents' => 1500],
            ],
        ], $body);
        self::assertNotSame($tiers[0]['id'], $tiers[1]['id']);

        $this->client->request('GET', '/api/events/' . $body['id']);
        self::assertResponseStatusCodeSame(404);
        $listed = $this->call('GET', self::EVENTS, $this->adminToken());
        $events = $listed['events'] ?? null;
        self::assertIsArray($events);
        self::assertCount(1, $events);
        $stored = $this->ask(new GetBackofficeEvent((string) $body['id']));
        self::assertSame($this->organization['id'], $this->ownerOf($stored->id));
    }

    public function testTheDetailShowsAnEventOfTheOrganization(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $body = $this->call('GET', self::EVENTS . '/' . $eventId, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame($eventId, $body['id'] ?? null);
        self::assertSame('La Cigale', $body['venue'] ?? null);
        self::assertSame(100, $body['capacity'] ?? null);
    }

    public function testRevisingADraftReplacesItsFieldsAndKeepsTheIdentifiersOfItsTiers(): void
    {
        $kept = PriceTierId::generate()->toString();
        $dropped = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, priceTiers: [
            ['id' => $kept, 'label' => 'Plein', 'priceCents' => 2500],
            ['id' => $dropped, 'label' => 'Réduit', 'priceCents' => 1500],
        ]);
        $payload = [...$this->payload(), 'title' => 'Nouveau titre', 'priceTiers' => [
            ['id' => $kept, 'label' => 'Plein tarif', 'priceCents' => 3000],
            ['label' => 'VIP', 'priceCents' => 9000],
        ]];

        $body = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), $payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Nouveau titre', $body['title'] ?? null);
        $tiers = $body['priceTiers'] ?? null;
        self::assertIsArray($tiers);
        self::assertCount(2, $tiers);
        self::assertIsArray($tiers[0]);
        self::assertIsArray($tiers[1]);
        self::assertSame(['id' => $kept, 'label' => 'Plein tarif', 'priceCents' => 3000], $tiers[0]);
        self::assertIsString($tiers[1]['id'] ?? null);
        self::assertTrue(Ulid::isValid($tiers[1]['id']));
        self::assertNotSame($dropped, $tiers[1]['id']);
        self::assertSame($body, $this->detailOf($eventId));
    }

    public function testATierOfAnotherEventIsUnprocessable(): void
    {
        $foreignTier = PriceTierId::generate()->toString();
        $this->createEvent($this->organization['id'], self::FUTURE, priceTiers: [['id' => $foreignTier, 'label' => 'Plein', 'priceCents' => 2500]]);
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $problem = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), [...$this->payload(), 'priceTiers' => [['id' => $foreignTier, 'label' => 'Volé', 'priceCents' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid_value', $problem['type'] ?? null);
    }

    public function testATierIdentifierUnknownToTheEventIsUnprocessableAndCreatesNoTier(): void
    {
        $own = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, priceTiers: [['id' => $own, 'label' => 'Plein', 'priceCents' => 2500]]);
        $chosenByTheClient = PriceTierId::generate()->toString();

        $problem = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), [...$this->payload(), 'priceTiers' => [['id' => $chosenByTheClient, 'label' => 'Inventé', 'priceCents' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid_value', $problem['type'] ?? null);
        self::assertSame([['id' => $own, 'label' => 'Plein', 'priceCents' => 2500]], $this->detailOf($eventId)['priceTiers']);
    }

    public function testATierWithANullIdentifierIsCreated(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $body = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), [...$this->payload(), 'priceTiers' => [['id' => null, 'label' => 'VIP', 'priceCents' => 9000]]]);

        self::assertResponseStatusCodeSame(200);
        $tiers = $body['priceTiers'] ?? null;
        self::assertIsArray($tiers);
        self::assertCount(1, $tiers);
        self::assertIsArray($tiers[0]);
        self::assertSame(['label' => 'VIP', 'priceCents' => 9000], array_diff_key($tiers[0], ['id' => true]));
        self::assertIsString($tiers[0]['id'] ?? null);
        self::assertTrue(Ulid::isValid($tiers[0]['id']));
    }

    public function testPublishingMakesTheEventVisibleAndUnpublishingHidesItAgain(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $published = $this->call('POST', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('published', $published['status'] ?? null);
        self::assertSame($eventId, $published['id'] ?? null);
        $this->client->request('GET', '/api/events/' . $eventId);
        self::assertResponseStatusCodeSame(200);

        $draft = $this->call('DELETE', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('draft', $draft['status'] ?? null);
        $this->client->request('GET', '/api/events/' . $eventId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRevisingAPublishedEventIsAConflict(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true);

        $problem = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), $this->payload());

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/event_not_draft', $problem['type'] ?? null);
    }

    public function testPublishingAPublishedEventIsAConflict(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true);

        $problem = $this->call('POST', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/event_already_published', $problem['type'] ?? null);
    }

    public function testUnpublishingADraftIsAConflict(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $problem = $this->call('DELETE', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/event_not_published', $problem['type'] ?? null);
    }

    public function testAPendingOrderLocksAPublishedEventAgainstUnpublication(): void
    {
        $eventId = $this->publishedEventWithPendingOrder();

        self::assertTrue($this->detailOf($eventId)['locked']);
        $problem = $this->call('DELETE', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/event_locked', $problem['type'] ?? null);
        self::assertSame('published', $this->detailOf($eventId)['status']);
    }

    public function testAPendingOrderLocksAPublishedEventAgainstRevision(): void
    {
        $eventId = $this->publishedEventWithPendingOrder();

        $problem = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), $this->payload());

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/event_locked', $problem['type'] ?? null);
    }

    public function testPublishingAnEventWhoseStartHasPassedIsUnprocessable(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);
        self::mockTime(new \DateTimeImmutable('2027-01-01T00:00:00+00:00'));

        $problem = $this->call('POST', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/event_start_in_past', $problem['type'] ?? null);
        self::assertSame('draft', $this->detailOf($eventId)['status']);
    }

    public function testCreationNeverUsesTheTierIdentifiersOfTheClient(): void
    {
        $other = $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $foreignTier = PriceTierId::generate()->toString();
        $foreignEvent = $this->createEvent($other['id'], self::FUTURE, priceTiers: [['id' => $foreignTier, 'label' => 'Plein', 'priceCents' => 2500]]);

        $body = $this->call('POST', self::EVENTS, $this->adminToken(), [...$this->payload(), 'priceTiers' => [['id' => $foreignTier, 'label' => 'Volé', 'priceCents' => 1]]]);

        self::assertResponseStatusCodeSame(201);
        $tiers = $body['priceTiers'] ?? null;
        self::assertIsArray($tiers);
        self::assertCount(1, $tiers);
        self::assertIsArray($tiers[0]);
        self::assertNotSame($foreignTier, $tiers[0]['id'] ?? null);
        self::assertSame('Volé', $tiers[0]['label'] ?? null);
        $foreign = $this->ask(new GetBackofficeEvent($foreignEvent));
        self::assertCount(1, $foreign->priceTiers);
        self::assertSame($foreignTier, $foreign->priceTiers[0]->priceTierId);
        self::assertSame('Plein', $foreign->priceTiers[0]->label);
        self::assertSame(2500, $foreign->priceTiers[0]->priceCents);
    }

    public function testAStartInThePastIsUnprocessableOnCreationAndRevision(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        foreach ([['POST', self::EVENTS], ['PUT', self::EVENTS . '/' . $eventId]] as [$method, $uri]) {
            $problem = $this->call($method, $uri, $this->adminToken(), [...$this->payload(), 'startsAt' => '2026-10-01T20:00:00+02:00']);

            self::assertResponseStatusCodeSame(422, $method);
            self::assertSame('/problems/event_start_in_past', $problem['type'] ?? null, $method);
        }
    }

    public function testPublishingAnEventWithoutTierIsUnprocessable(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, priceTiers: []);

        $problem = $this->call('POST', self::EVENTS . '/' . $eventId . '/publication', $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/event_without_price_tier', $problem['type'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidEvents(): iterable
    {
        yield 'empty title' => [['title' => ''], '/problems/invalid_value'];
        yield 'negative capacity' => [['capacity' => 0], '/problems/invalid_value'];
        yield 'start without offset' => [['startsAt' => '2026-12-01T20:00:00'], '/problems/invalid_value'];
        yield 'start that is not a date' => [['startsAt' => 'demain'], '/problems/invalid_value'];
        yield 'negative price' => [['priceTiers' => [['label' => 'Plein', 'priceCents' => -1]]], '/problems/invalid_value'];
        yield 'empty tier label' => [['priceTiers' => [['label' => '', 'priceCents' => 100]]], '/problems/invalid_value'];
        yield 'title that is not a string' => [['title' => 42], '/problems/validation_failed'];
        yield 'capacity that is not an integer' => [['capacity' => 'cent'], '/problems/validation_failed'];
        yield 'tiers that are not a list' => [['priceTiers' => 'gratuit'], '/problems/validation_failed'];
        yield 'tier without price' => [['priceTiers' => [['label' => 'Plein']]], '/problems/validation_failed'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidEvents')]
    public function testAnInvalidEventIsUnprocessableOnCreationAndRevision(array $override, string $type): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        foreach ([['POST', self::EVENTS], ['PUT', self::EVENTS . '/' . $eventId]] as [$method, $uri]) {
            $problem = $this->call($method, $uri, $this->adminToken(), [...$this->payload(), ...$override]);

            self::assertResponseStatusCodeSame(422, $method);
            self::assertSame($type, $problem['type'] ?? null, $method);
        }
        $listed = $this->call('GET', self::EVENTS, $this->adminToken());
        self::assertIsArray($listed['events'] ?? null);
        self::assertCount(1, $listed['events']);
        self::assertSame('Concert', $this->detailOf($eventId)['title']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidTierIdentifiers(): iterable
    {
        yield 'malformed tier identifier' => [['priceTiers' => [['id' => 'abc', 'label' => 'Plein', 'priceCents' => 100]]]];
        yield 'twice the same tier' => [['priceTiers' => [['id' => '01JAE5Z8Q3M4N6P7R8S9T0V1W2', 'label' => 'A', 'priceCents' => 1], ['id' => '01JAE5Z8Q3M4N6P7R8S9T0V1W2', 'label' => 'B', 'priceCents' => 2]]]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidTierIdentifiers')]
    public function testInvalidTierIdentifiersAreUnprocessableOnRevision(array $override): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE);

        $problem = $this->call('PUT', self::EVENTS . '/' . $eventId, $this->adminToken(), [...$this->payload(), ...$override]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid_value', $problem['type'] ?? null);
        self::assertSame('Concert', $this->detailOf($eventId)['title']);
    }

    public function testApiDocDescribesTheEventRoutes(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertIsArray($doc);
        self::assertIsArray($doc['paths'] ?? null);
        $routes = [
            [self::EVENTS, 'get', ['200', '401', '403']],
            [self::EVENTS, 'post', ['201', '401', '403', '422']],
            [self::EVENTS . '/{eventId}', 'get', ['200', '401', '403']],
            [self::EVENTS . '/{eventId}', 'put', ['200', '401', '403', '409', '422']],
            [self::EVENTS . '/{eventId}/publication', 'post', ['200', '401', '403', '409', '422']],
            [self::EVENTS . '/{eventId}/publication', 'delete', ['200', '401', '403', '409']],
        ];
        foreach ($routes as [$path, $verb, $statuses]) {
            self::assertIsArray($doc['paths'][$path] ?? null, $path);
            $operation = $doc['paths'][$path][$verb] ?? null;
            self::assertIsArray($operation, $path . ' ' . $verb);
            self::assertIsArray($operation['responses'] ?? null);
            self::assertSame([['bearerAuth' => []]], $operation['security']);
            self::assertSame(['Backoffice'], $operation['tags']);
            foreach ($statuses as $status) {
                self::assertArrayHasKey($status, $operation['responses'], $path . ' ' . $verb);
            }
        }

        foreach (['CreateEventRequest', 'EventRequest'] as $request) {
            self::assertSame(200, $this->docNode($doc, 'components', 'schemas', $request, 'properties', 'capacity', 'example'), $request);
        }
        foreach (['NewPriceTierRequest', 'PriceTierRequest'] as $request) {
            self::assertSame(2500, $this->docNode($doc, 'components', 'schemas', $request, 'properties', 'priceCents', 'example'), $request);
        }
        self::assertTrue($this->docNode($doc, 'components', 'schemas', 'PriceTierRequest', 'properties', 'id', 'nullable'));
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

    private function publishedEventWithPendingOrder(): string
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true);
        $tiers = $this->detailOf($eventId)['priceTiers'];
        self::assertIsArray($tiers);
        self::assertIsArray($tiers[0]);
        $this->client->request('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'eventId' => $eventId,
            'buyerEmail' => 'acheteur@example.test',
            'lines' => [['priceTierId' => $tiers[0]['id'], 'quantity' => 1]],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $eventId;
    }

    /**
     * @return list<array{string, string}>
     */
    private function eventRoutes(string $eventId): array
    {
        return [
            ['GET', self::EVENTS . '/' . $eventId],
            ['PUT', self::EVENTS . '/' . $eventId],
            ['POST', self::EVENTS . '/' . $eventId . '/publication'],
            ['DELETE', self::EVENTS . '/' . $eventId . '/publication'],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private function routeSet(string $eventId): array
    {
        return [['GET', self::EVENTS], ['POST', self::EVENTS], ...$this->eventRoutes($eventId)];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => 'Soirée Rock',
            'description' => 'Une belle soirée',
            'venue' => 'La Cigale',
            'startsAt' => self::FUTURE,
            'capacity' => 150,
            'priceTiers' => [['label' => 'Plein', 'priceCents' => 2500], ['label' => 'Réduit', 'priceCents' => 1500]],
        ];
    }

    private function adminToken(): string
    {
        return TestTokenIssuer::issue(['realm_access' => ['roles' => ['organization-admin']]]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function detailOf(string $eventId): array
    {
        return $this->call('GET', self::EVENTS . '/' . $eventId, $this->adminToken());
    }

    private function ownerOf(string $eventId): string
    {
        $connection = self::getContainer()->get(Connection::class);
        $owner = $connection->fetchOne('SELECT organization_id FROM event WHERE id = :id', ['id' => $eventId]);
        \assert(\is_string($owner));

        return $owner;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<array-key, mixed>
     */
    private function call(string $method, string $uri, ?string $accessToken, ?array $payload = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        }
        $this->client->request($method, $uri, server: $server, content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
