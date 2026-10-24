<?php

declare(strict_types=1);

namespace App\Tests\Sales\UI\Http;

use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Tests\Catalog\CatalogFixturesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Ulid;

final class SalesApiTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    private const string ACCEPTED_CARD = '4242424242424242';
    private const string DECLINED_CARD = '4000000000000002';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable(self::NOW));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
        $this->client = self::createClient();
    }

    public function testPlaceOrderReturns201WithLocation(): void
    {
        $event = $this->eventOnSale();
        $email = $this->uniqueEmail();

        $body = $this->send('POST', '/api/orders', $this->orderPayload($event, 2, $email));

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        self::assertTrue(Ulid::isValid($body['id']));
        self::assertSame('pending', $body['status']);
        self::assertSame(['id', 'status'], array_keys($body));
        self::assertResponseHeaderSame('Location', '/api/orders/' . $body['id']);
    }

    public function testPlacingFreeOrderReturnsPaid(): void
    {
        $event = $this->eventOnSale(prices: [0]);

        $body = $this->send('POST', '/api/orders', $this->orderPayload($event, 1, $this->uniqueEmail()));

        self::assertResponseStatusCodeSame(201);
        self::assertIsArray($body);
        self::assertSame('paid', $body['status']);
    }

    public function testQuantityZeroReturns422(): void
    {
        $event = $this->eventOnSale();

        $this->assertProblemCode('/api/orders', $this->orderPayload($event, 0, $this->uniqueEmail()), 422, 'invalid_value');
    }

    public function testElevenTicketsReturns422(): void
    {
        $event = $this->eventOnSale();

        $this->assertProblemCode('/api/orders', $this->orderPayload($event, 11, $this->uniqueEmail()), 422, 'order_too_large');
    }

    public function testInvalidEmailReturns422(): void
    {
        $event = $this->eventOnSale();

        $this->assertProblemCode('/api/orders', $this->orderPayload($event, 1, 'not-an-email'), 422, 'invalid_value');
    }

    public function testEmptyLinesReturns422(): void
    {
        $event = $this->eventOnSale();

        $this->assertProblemCode('/api/orders', ['eventId' => $event['eventId'], 'buyerEmail' => $this->uniqueEmail(), 'lines' => []], 422, 'empty_order');
    }

    public function testUnknownPriceTierReturns422(): void
    {
        $event = $this->eventOnSale();
        $payload = ['eventId' => $event['eventId'], 'buyerEmail' => $this->uniqueEmail(), 'lines' => [['priceTierId' => PriceTierId::generate()->toString(), 'quantity' => 1]]];

        $this->assertProblemCode('/api/orders', $payload, 422, 'unknown_price_tier');
    }

    public function testSoldOutReturns409(): void
    {
        $event = $this->eventOnSale(capacity: 1);

        $this->assertProblemCode('/api/orders', $this->orderPayload($event, 2, $this->uniqueEmail()), 409, 'sold_out');
    }

    public function testEventNotOnSaleReturns422(): void
    {
        $payload = ['eventId' => EventId::generate()->toString(), 'buyerEmail' => $this->uniqueEmail(), 'lines' => [['priceTierId' => PriceTierId::generate()->toString(), 'quantity' => 1]]];

        $this->assertProblemCode('/api/orders', $payload, 422, 'event_not_on_sale');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function structurallyInvalidOrders(): iterable
    {
        yield 'missing email' => [['eventId' => 'x', 'lines' => []], 'buyerEmail'];
        yield 'missing event' => [['buyerEmail' => 'a@b.fr', 'lines' => []], 'eventId'];
        yield 'missing lines' => [['eventId' => 'x', 'buyerEmail' => 'a@b.fr'], 'lines'];
        yield 'quantity is not an integer' => [['eventId' => 'x', 'buyerEmail' => 'a@b.fr', 'lines' => [['priceTierId' => 'y', 'quantity' => 'two']]], 'lines[0].quantity'];
        yield 'line without tier' => [['eventId' => 'x', 'buyerEmail' => 'a@b.fr', 'lines' => [['quantity' => 1]]], 'lines[0].priceTierId'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('structurallyInvalidOrders')]
    public function testStructurallyInvalidOrderReturnsValidationProblem(array $payload, string $propertyPath): void
    {
        $problem = $this->send('POST', '/api/orders', $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/validation_failed', $problem['type']);
        self::assertSame('Unprocessable Content', $problem['title']);
        self::assertSame(422, $problem['status']);
        self::assertSame('/api/orders', $problem['instance']);
        self::assertIsArray($problem['violations']);
        $paths = array_column($problem['violations'], 'propertyPath');
        self::assertContains($propertyPath, $paths);
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertSame(['propertyPath', 'message'], array_keys($violation));
            self::assertIsString($violation['message']);
            self::assertNotSame('', $violation['message']);
        }
    }

    public function testMalformedJsonReturns400(): void
    {
        $this->client->request('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/bad_request', $this->decode()['type'] ?? null);
    }

    public function testPaymentWith4242ReturnsPaid(): void
    {
        $orderId = $this->placeOrder();

        $body = $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::ACCEPTED_CARD]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['status' => 'paid'], $body);
        $status = $this->send('GET', '/api/orders/' . $orderId);
        self::assertIsArray($status);
        self::assertSame('paid', $status['status']);
    }

    public function testPaymentWith4000000000000002Returns402(): void
    {
        $orderId = $this->placeOrder();

        $problem = $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::DECLINED_CARD]);

        self::assertResponseStatusCodeSame(402);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/payment_declined', $problem['type']);
        self::assertSame('Payment Required', $problem['title']);
        self::assertSame(402, $problem['status']);
        self::assertSame('The payment was declined.', $problem['detail']);
        self::assertSame('/api/orders/' . $orderId . '/payment', $problem['instance']);
        self::assertSame('failed', $problem['orderStatus']);
        self::assertStringNotContainsString(self::DECLINED_CARD, (string) $this->client->getResponse()->getContent());
    }

    public function testPaymentAfterExpiryReturns409OrderExpired(): void
    {
        $orderId = $this->placeOrder();
        $this->clock->modify('+1 day');

        $problem = $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::ACCEPTED_CARD]);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/order_expired', $problem['type']);
        self::assertSame('Conflict', $problem['title']);
        self::assertSame(409, $problem['status']);
        self::assertSame('The order expired before it was paid.', $problem['detail']);
        self::assertSame('/api/orders/' . $orderId . '/payment', $problem['instance']);
        self::assertSame('expired', $problem['orderStatus']);
    }

    public function testPayingTwiceReturns409OrderNotPending(): void
    {
        $orderId = $this->placeOrder();
        $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::ACCEPTED_CARD]);

        $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::ACCEPTED_CARD]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/order_not_pending', $this->decode()['type'] ?? null);
    }

    public function testPayingUnknownOrderReturns404(): void
    {
        $this->assertProblemCode('/api/orders/' . OrderId::generate()->toString() . '/payment', ['cardNumber' => self::ACCEPTED_CARD], 404, 'order_not_found');
    }

    public function testMalformedCardReturns422WithoutEchoingIt(): void
    {
        $orderId = $this->placeOrder();

        $this->send('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => '1234']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/invalid_value', $this->decode()['type'] ?? null);
        self::assertStringNotContainsString('1234', (string) $this->client->getResponse()->getContent());
    }

    public function testMissingCardReturnsValidationProblem(): void
    {
        $orderId = $this->placeOrder();

        $this->send('POST', '/api/orders/' . $orderId . '/payment', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/validation_failed', $this->decode()['type'] ?? null);
    }

    public function testOrderStatusDoesNotExposeEmail(): void
    {
        $email = $this->uniqueEmail();
        $event = $this->eventOnSale(prices: [2500, 1000]);
        $created = $this->send('POST', '/api/orders', [
            'eventId' => $event['eventId'],
            'buyerEmail' => $email,
            'lines' => [
                ['priceTierId' => $event['priceTierIds'][0], 'quantity' => 2],
                ['priceTierId' => $event['priceTierIds'][1], 'quantity' => 1],
            ],
        ]);
        self::assertIsArray($created);
        self::assertIsString($created['id']);

        $status = $this->send('GET', '/api/orders/' . $created['id']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'id' => $created['id'],
            'status' => 'pending',
            'eventId' => $event['eventId'],
            'lines' => [
                ['priceTierId' => $event['priceTierIds'][0], 'quantity' => 2, 'unitPriceCents' => 2500],
                ['priceTierId' => $event['priceTierIds'][1], 'quantity' => 1, 'unitPriceCents' => 1000],
            ],
            'totalCents' => 6000,
        ], $status);
        self::assertStringNotContainsString($email, (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownOrderReturns404(): void
    {
        $orderId = OrderId::generate()->toString();

        $problem = $this->send('GET', '/api/orders/' . $orderId);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/order_not_found', $problem['type']);
        self::assertSame('/api/orders/' . $orderId, $problem['instance']);
    }

    public function testAvailabilityReflectsReservation(): void
    {
        $event = $this->eventOnSale(capacity: 10);
        $this->send('POST', '/api/orders', $this->orderPayload($event, 3, $this->uniqueEmail()));

        $availability = $this->send('GET', '/api/events/' . $event['eventId'] . '/availability');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['eventId' => $event['eventId'], 'remaining' => 7], $availability);
    }

    public function testAvailabilityOfEventWithoutStockReturns422(): void
    {
        $this->send('GET', '/api/events/' . EventId::generate()->toString() . '/availability');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/problems/event_not_on_sale', $this->decode()['type'] ?? null);
    }

    public function testApiDocJsonListsSalesPaths(): void
    {
        $doc = $this->send('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        self::assertIsArray($doc);
        self::assertIsArray($doc['paths']);
        $expected = [
            '/api/orders' => ['post', ['201', '409', '422']],
            '/api/orders/{orderId}/payment' => ['post', ['200', '402', '409', '422']],
            '/api/orders/{orderId}' => ['get', ['200', '404']],
            '/api/events/{eventId}/availability' => ['get', ['200', '422']],
        ];
        foreach ($expected as $path => [$method, $statuses]) {
            self::assertArrayHasKey($path, $doc['paths']);
            self::assertIsArray($doc['paths'][$path]);
            self::assertIsArray($doc['paths'][$path][$method]);
            self::assertIsArray($doc['paths'][$path][$method]['responses']);
            foreach ($statuses as $status) {
                self::assertArrayHasKey($status, $doc['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(2, $this->docNode($doc, 'components', 'schemas', 'OrderLineRequest', 'properties', 'quantity', 'example'));
        self::assertSame(42, $this->docNode($doc, 'paths', '/api/events/{eventId}/availability', 'get', 'responses', '200', 'content', 'application/json', 'schema', 'properties', 'remaining', 'example'));
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

    /**
     * @param array<string, mixed> $payload
     */
    private function assertProblemCode(string $uri, array $payload, int $status, string $code): void
    {
        $problem = $this->send('POST', $uri, $payload);

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertIsArray($problem);
        self::assertSame('/problems/' . $code, $problem['type']);
        self::assertSame($status, $problem['status']);
        self::assertSame($uri, $problem['instance']);
    }

    private function uniqueEmail(): string
    {
        return strtolower(OrderId::generate()->toString()) . '@example.com';
    }

    private function placeOrder(): string
    {
        $event = $this->eventOnSale();
        $body = $this->send('POST', '/api/orders', $this->orderPayload($event, 1, $this->uniqueEmail()));
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    /**
     * @param array{eventId: string, priceTierIds: list<string>} $event
     *
     * @return array<string, mixed>
     */
    private function orderPayload(array $event, int $quantity, string $email): array
    {
        return ['eventId' => $event['eventId'], 'buyerEmail' => $email, 'lines' => [['priceTierId' => $event['priceTierIds'][0], 'quantity' => $quantity]]];
    }

    /**
     * @param list<int> $prices
     *
     * @return array{eventId: string, priceTierIds: list<string>}
     */
    private function eventOnSale(int $capacity = 100, array $prices = [2500]): array
    {
        $priceTiers = array_map(
            static fn(int $priceCents): array => ['id' => PriceTierId::generate()->toString(), 'label' => 'Tarif', 'priceCents' => $priceCents],
            $prices,
        );
        $eventId = $this->createEvent($this->createOrganization()['id'], '2026-12-01T20:00:00+00:00', publish: true, priceTiers: $priceTiers, capacity: $capacity);

        return ['eventId' => $eventId, 'priceTierIds' => array_column($priceTiers, 'id')];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function send(string $method, string $uri, ?array $payload = null): mixed
    {
        $this->client->request($method, $uri, server: null === $payload ? [] : ['CONTENT_TYPE' => 'application/json'], content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
