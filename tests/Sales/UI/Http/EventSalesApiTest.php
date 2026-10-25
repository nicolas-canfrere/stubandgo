<?php

declare(strict_types=1);

namespace App\Tests\Sales\UI\Http;

use App\Sales\Core\Application\Command\ExpireOrder;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Support\Security\TestTokenIssuer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class EventSalesApiTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    private const string OTHER_KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a99';
    private const string FUTURE = '2026-12-01T20:00:00+01:00';
    private const string ACCEPTED_CARD = '4242424242424242';
    private const string DECLINED_CARD = '4000000000000002';

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

    public function testTheRouteRequiresAnAccessToken(): void
    {
        $problem = $this->get('01JAE5Z8Q3M4N6P7R8S9T0V1W2', null);

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
    public function testWhoIsNotAnAdministratorOfTheOrganizationIsForbidden(array $claims): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true);

        $problem = $this->get($eventId, TestTokenIssuer::issue($claims));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
    }

    public function testTheAdministratorOfAnotherOrganizationIsForbidden(): void
    {
        $other = $this->createOrganization(keycloakOrganizationId: self::OTHER_KEYCLOAK_ID);
        $foreignEvent = $this->createEvent($other['id'], self::FUTURE, publish: true);

        $problem = $this->get($foreignEvent, $this->adminToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
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
        $problem = $this->get($eventId, $this->adminToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
    }

    public function testOnlyThePaidOrdersCountInTheSalesAndTheirFrozenAmountsAreSummed(): void
    {
        [$eventId, $plein, $reduit, $vip] = $this->eventWithThreeTiers();
        $this->payOrder($this->placeOrder($eventId, [[$plein, 2], [$reduit, 1]]));
        $this->setCommission(2000);
        $this->payOrder($this->placeOrder($eventId, [[$plein, 1]]));
        $this->placeOrder($eventId, [[$reduit, 3]]);
        $this->payOrder($this->placeOrder($eventId, [[$plein, 1]]), self::DECLINED_CARD);
        $expired = $this->placeOrder($eventId, [[$plein, 2]]);
        self::mockTime(new \DateTimeImmutable('2026-10-24T12:20:00+00:00'));
        $this->command(new ExpireOrder($expired));
        $otherEvent = $this->createEvent($this->organization['id'], self::FUTURE, publish: true);
        $this->payOrder($this->placeOrder($otherEvent, [[$this->tierIdsOf($otherEvent)[0], 5]]));

        $body = $this->get($eventId, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'eventId' => $eventId,
            'seatsRemaining' => 93,
            'ticketsSold' => 4,
            'totalCents' => 9000,
            'commissionCents' => 1150,
            'netCents' => 7850,
            'priceTiers' => [
                ['priceTierId' => $plein, 'label' => 'Plein', 'ticketsSold' => 3, 'totalCents' => 7500],
                ['priceTierId' => $reduit, 'label' => 'Réduit', 'ticketsSold' => 1, 'totalCents' => 1500],
                ['priceTierId' => $vip, 'label' => 'VIP', 'ticketsSold' => 0, 'totalCents' => 0],
            ],
        ], $body);
    }

    public function testAnEventNeverPublishedHasAllItsSeatsAndNoSale(): void
    {
        $plein = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, priceTiers: [['id' => $plein, 'label' => 'Plein', 'priceCents' => 2500]], capacity: 150);

        $body = $this->get($eventId, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'eventId' => $eventId,
            'seatsRemaining' => 150,
            'ticketsSold' => 0,
            'totalCents' => 0,
            'commissionCents' => 0,
            'netCents' => 0,
            'priceTiers' => [['priceTierId' => $plein, 'label' => 'Plein', 'ticketsSold' => 0, 'totalCents' => 0]],
        ], $body);
    }

    public function testAnUnsoldPublishedEventHasAllItsSeats(): void
    {
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true, capacity: 80);

        $body = $this->get($eventId, $this->adminToken());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(80, $body['seatsRemaining'] ?? null);
        self::assertSame(0, $body['ticketsSold'] ?? null);
    }

    public function testApiDocDescribesTheSalesRoute(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($doc);
        self::assertIsArray($doc['paths'] ?? null);
        self::assertIsArray($doc['paths']['/api/backoffice/events/{eventId}/sales'] ?? null);
        $operation = $doc['paths']['/api/backoffice/events/{eventId}/sales']['get'] ?? null;
        self::assertIsArray($operation);
        self::assertIsArray($operation['responses'] ?? null);
        self::assertSame([['bearerAuth' => []]], $operation['security']);
        self::assertSame(['Backoffice'], $operation['tags']);
        foreach (['200', '401', '403'] as $status) {
            self::assertArrayHasKey($status, $operation['responses']);
        }
    }

    /**
     * @return array{string, string, string, string}
     */
    private function eventWithThreeTiers(): array
    {
        $plein = PriceTierId::generate()->toString();
        $reduit = PriceTierId::generate()->toString();
        $vip = PriceTierId::generate()->toString();
        $eventId = $this->createEvent($this->organization['id'], self::FUTURE, publish: true, priceTiers: [
            ['id' => $plein, 'label' => 'Plein', 'priceCents' => 2500],
            ['id' => $reduit, 'label' => 'Réduit', 'priceCents' => 1500],
            ['id' => $vip, 'label' => 'VIP', 'priceCents' => 9000],
        ]);

        return [$eventId, $plein, $reduit, $vip];
    }

    /**
     * @return list<string>
     */
    private function tierIdsOf(string $eventId): array
    {
        $ids = self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT id FROM price_tier WHERE event_id = :id', ['id' => $eventId]);

        return array_map(static fn(mixed $id): string => \is_string($id) ? $id : throw new \LogicException('A price tier id is a string.'), $ids);
    }

    private function setCommission(int $basisPoints): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE organization SET commission_basis_points = :rate WHERE id = :id',
            ['rate' => $basisPoints, 'id' => $this->organization['id']],
        );
    }

    /**
     * @param list<array{string, int}> $lines
     */
    private function placeOrder(string $eventId, array $lines): string
    {
        $this->client->request('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'eventId' => $eventId,
            'buyerEmail' => 'acheteur@example.test',
            'lines' => array_map(static fn(array $line): array => ['priceTierId' => $line[0], 'quantity' => $line[1]], $lines),
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($body) && \is_string($body['id']));

        return $body['id'];
    }

    private function payOrder(string $orderId, string $card = self::ACCEPTED_CARD): void
    {
        $this->client->request('POST', '/api/orders/' . $orderId . '/payment', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['cardNumber' => $card], \JSON_THROW_ON_ERROR));
    }

    private function adminToken(): string
    {
        return TestTokenIssuer::issue(['realm_access' => ['roles' => ['organization-admin']]]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function get(string $eventId, ?string $accessToken): array
    {
        $server = null === $accessToken ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken];
        $this->client->request('GET', '/api/backoffice/events/' . $eventId . '/sales', server: $server);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
