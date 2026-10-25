<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use App\Access\Core\Application\Command\DeliverTickets;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\Infrastructure\Observability\CorrelationContext;
use App\Shared\Infrastructure\Observability\TraceParent;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Tests\Access\Fixtures\ControllableTicketTokenGenerator;
use App\Tests\Catalog\CatalogFixturesTrait;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Follows one HTTP payment through the outbox, the relay and the worker.
 */
final class CorrelationPropagationTest extends WebTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;
    use LogRecordsTrait;

    private const string CARD_NUMBER = '4242424242424242';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
    }

    public function testTraceParentSurvivesOutboxAndAsyncHandling(): void
    {
        $orderId = $this->pendingOrder();
        $incoming = TraceParent::generate();

        $this->pay($orderId, $incoming->toHeader());
        self::assertResponseIsSuccessful();
        // The relay and the worker run in other processes in production, without the request's context: rebooting
        // runs the services reset the kernel deferred after the request, and the context is reset again before the worker.
        $this->kernel()->boot();
        self::assertSame(1, self::getContainer()->get(OutboxRelay::class)->relayBatch());
        self::getContainer()->get(CorrelationContext::class)->reset();
        // The worker resets services (this handler included) after each message.
        self::logHandler()->setSkipReset(true);
        self::logHandler()->clear();
        $this->consumeOneMessage();

        $delivered = array_values(array_filter(
            self::logRecords('command.handled'),
            static fn($record): bool => DeliverTickets::class === ($record->context['command'] ?? null),
        ));
        self::assertCount(1, $delivered);
        self::assertSame($incoming->traceId(), $delivered[0]->extra['trace_id'] ?? null);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function paymentOutcomes(): iterable
    {
        yield 'payment succeeds' => [false];
        yield 'payment fails unexpectedly' => [true];
    }

    #[DataProvider('paymentOutcomes')]
    public function testCardNumberNeverAppearsInLogs(bool $ticketIssuingFails): void
    {
        $orderId = $this->pendingOrder();
        if ($ticketIssuingFails) {
            self::getContainer()->get(ControllableTicketTokenGenerator::class)->failFromNowOn();
        }

        $this->pay($orderId, TraceParent::generate()->toHeader());

        self::assertSame($ticketIssuingFails ? 500 : 200, $this->client->getResponse()->getStatusCode());
        self::assertNotEmpty(self::logRecords($ticketIssuingFails ? 'command.failed' : 'command.handled'));
        $formatter = new JsonFormatter();
        $formatter->includeStacktraces();
        foreach (self::logRecords() as $record) {
            self::assertStringNotContainsString(self::CARD_NUMBER, $formatter->format($record));
        }
    }

    private function pendingOrder(): string
    {
        $tier = PriceTierId::generate()->toString();
        $eventId = $this->createEvent(
            $this->createOrganization()['id'],
            '2026-12-01T20:00:00+00:00',
            publish: true,
            priceTiers: [['id' => $tier, 'label' => 'Tarif', 'priceCents' => 2500]],
        );
        $orderId = OrderId::generate()->toString();
        $this->command(new PlaceOrder($orderId, $eventId, strtolower($orderId) . '@example.com', [['priceTierId' => $tier, 'quantity' => 1]]));

        return $orderId;
    }

    private function pay(string $orderId, string $traceParent): void
    {
        $this->client->request(
            'POST',
            '/api/orders/' . $orderId . '/payment',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_TRACEPARENT' => $traceParent],
            content: json_encode(['cardNumber' => self::CARD_NUMBER], \JSON_THROW_ON_ERROR),
        );
    }

    private function kernel(): KernelInterface
    {
        self::assertNotNull(self::$kernel);

        return self::$kernel;
    }

    private function consumeOneMessage(): void
    {
        $tester = new CommandTester(new Application($this->kernel())->find('messenger:consume'));

        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 10]);

        $tester->assertCommandIsSuccessful();
    }
}
