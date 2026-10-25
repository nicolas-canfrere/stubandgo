<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Tests\Access\Fixtures\RecordingQrCodeRenderer;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Support\Security\TestTokenIssuer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mime\Email;

/**
 * The demo expected at the end of L1, from the demo data to the second scan of a ticket.
 */
final class L1DemoFlowTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    private const string ACCEPTED_CARD = '4242424242424242';
    private const string REFUSED_CARD = '4000000000000002';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
    }

    public function testFullL1Flow(): void
    {
        $this->loadDemoData();

        $events = $this->json('GET', '/api/events');
        self::assertResponseIsSuccessful();
        $rock = $this->eventTitled($events, 'Soirée Rock');
        $eventId = $this->string($rock, 'id');

        $detail = $this->json('GET', '/api/events/' . $eventId);
        $tiers = $this->tiersByLabel($detail);
        $lines = [['priceTierId' => $tiers['Plein'], 'quantity' => 1], ['priceTierId' => $tiers['Réduit'], 'quantity' => 1]];
        $initial = $this->remaining($eventId);

        $refusedOrder = $this->placeOrder($eventId, $lines);
        self::assertSame($initial - 2, $this->remaining($eventId));
        $this->json('POST', '/api/orders/' . $refusedOrder . '/payment', ['cardNumber' => self::REFUSED_CARD]);
        self::assertResponseStatusCodeSame(402);
        self::assertSame($initial, $this->remaining($eventId));

        $orderId = $this->placeOrder($eventId, $lines);
        self::assertSame($initial - 2, $this->remaining($eventId));
        $paid = $this->json('POST', '/api/orders/' . $orderId . '/payment', ['cardNumber' => self::ACCEPTED_CARD]);
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $paid['status']);
        self::assertSame('paid', $this->json('GET', '/api/orders/' . $orderId)['status']);

        $this->relayAndConsume();

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'buyer@example.com');
        self::assertEmailAttachmentCount($email, 2);
        $tokens = self::getContainer()->get(RecordingQrCodeRenderer::class)->payloads();
        self::assertCount(2, $tokens);

        // The gate is held by the controller of Rock Asso, the demo organization of the event.
        $controller = TestTokenIssuer::issue();
        $first = $this->json('POST', '/api/events/' . $eventId . '/scans', ['token' => $tokens[0]], $controller);
        self::assertResponseIsSuccessful();
        self::assertSame('valid', $first['outcome']);
        self::assertNull($first['firstUsedAt']);

        $second = $this->json('POST', '/api/events/' . $eventId . '/scans', ['token' => $tokens[0]], $controller);
        self::assertResponseIsSuccessful();
        self::assertSame('already_used', $second['outcome']);
        self::assertSame('2026-10-24T12:00:00Z', $second['firstUsedAt']);
    }

    private function loadDemoData(): void
    {
        $tester = new CommandTester(new Application($this->kernel())->find('app:demo:load'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
    }

    /**
     * @param list<array{priceTierId: string, quantity: int}> $lines
     */
    private function placeOrder(string $eventId, array $lines): string
    {
        $order = $this->json('POST', '/api/orders', ['eventId' => $eventId, 'buyerEmail' => 'buyer@example.com', 'lines' => $lines]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $order['status']);

        return $this->string($order, 'id');
    }

    private function remaining(string $eventId): int
    {
        $availability = $this->json('GET', '/api/events/' . $eventId . '/availability');
        self::assertIsInt($availability['remaining']);

        return $availability['remaining'];
    }

    private function relayAndConsume(): void
    {
        // The relay and the worker run in other processes in production, without the request's context.
        $this->kernel()->boot();
        self::assertSame(1, self::getContainer()->get(OutboxRelay::class)->relayBatch());
        self::getContainer()->get(CorrelationContext::class)->reset();
        $tester = new CommandTester(new Application($this->kernel())->find('messenger:consume'));

        // Without the reset a worker runs after each message, which would empty the mailer's message logger.
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10, '--no-reset' => null]);

        $tester->assertCommandIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed>
     */
    private function json(string $method, string $uri, ?array $body = null, ?string $accessToken = null): array
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', ...(null === $accessToken ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken])],
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $events
     *
     * @return array<array-key, mixed>
     */
    private function eventTitled(array $events, string $title): array
    {
        foreach ($events as $event) {
            if (\is_array($event) && $title === ($event['title'] ?? null)) {
                return $event;
            }
        }

        self::fail(\sprintf('Event "%s" is missing from the list.', $title));
    }

    /**
     * @param array<array-key, mixed> $detail
     *
     * @return array<string, string>
     */
    private function tiersByLabel(array $detail): array
    {
        $tiers = [];
        $priceTiers = $detail['priceTiers'] ?? null;
        self::assertIsArray($priceTiers);
        foreach ($priceTiers as $tier) {
            self::assertIsArray($tier);
            $tiers[$this->string($tier, 'label')] = $this->string($tier, 'id');
        }

        return $tiers;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        self::assertIsString($data[$key] ?? null);

        return $data[$key];
    }

    private function kernel(): KernelInterface
    {
        self::assertNotNull(self::$kernel);

        return self::$kernel;
    }
}
