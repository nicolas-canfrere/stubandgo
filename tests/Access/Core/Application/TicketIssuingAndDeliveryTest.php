<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Application;

use App\Access\Core\Application\Command\DeliverTickets;
use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Infrastructure\Messenger\DeliverTicketsMessage;
use App\Sales\Core\Application\Command\PayOrder;
use App\Shared\Identity\OrderId;
use App\Tests\Access\Fixtures\ControllableTicketTokenGenerator;
use App\Tests\Access\Fixtures\RecordingQrCodeRenderer;
use App\Tests\Sales\AbstractSalesTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mime\Email;

final class TicketIssuingAndDeliveryTest extends AbstractSalesTestCase
{
    private const string ACCEPTED_CARD = '4242424242424242';

    public function testPaidOrderIssuesTicketsAndStoresOutboxMessage(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$full, $reduced]] = $this->eventOnSale(pricesCents: [2500, 1000]);
        $orderId = $this->placeOrder($eventId, [
            ['priceTierId' => $full, 'quantity' => 2],
            ['priceTierId' => $reduced, 'quantity' => 1],
        ], 'Buyer@Example.com');
        $outboxBefore = $this->outboxCount();

        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        $bundle = $this->bundles()->get(OrderId::fromString($orderId));
        self::assertSame($eventId, $bundle->eventId()->toString());
        self::assertSame('buyer@example.com', $bundle->buyerEmail()->toString());
        self::assertFalse($bundle->isDelivered());
        self::assertSame([$full, $full, $reduced], array_map(static fn($ticket): string => $ticket->priceTierId()->toString(), $bundle->tickets()));
        $tokens = array_map(static fn($ticket): string => $ticket->admissionRight()->token()->toString(), $bundle->tickets());
        self::assertCount(3, array_unique($tokens));
        foreach ($bundle->tickets() as $ticket) {
            self::assertSame($eventId, $ticket->admissionRight()->eventId()->toString());
            self::assertFalse($ticket->admissionRight()->isUsed());
        }
        self::assertSame($outboxBefore + 1, $this->outboxCount());
        $payload = $this->connection()->fetchOne(
            'SELECT payload FROM outbox_message WHERE message_class = :class AND payload ->> \'orderId\' = :order_id',
            ['class' => DeliverTicketsMessage::class, 'order_id' => $orderId],
        );
        self::assertIsString($payload);
        self::assertSame(['orderId' => $orderId], json_decode($payload, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testFreeOrderIssuesTicketsToo(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(pricesCents: [0]);

        $orderId = $this->placeSingleLineOrder($eventId, $tier, 2);

        self::assertCount(2, $this->bundles()->get(OrderId::fromString($orderId))->tickets());
    }

    public function testFailureInTicketIssuingRollsBackPayment(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        $orderId = $this->placeSingleLineOrder($eventId, $tier);
        $outboxBefore = $this->outboxCount();
        self::getContainer()->get(ControllableTicketTokenGenerator::class)->failFromNowOn();

        try {
            $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));
            self::fail('A failure while issuing tickets must fail the payment.');
        } catch (\Throwable) {
        }

        self::assertSame('pending', $this->connection()->fetchOne('SELECT status FROM sales_order WHERE id = ?', [$orderId]));
        self::assertSame($outboxBefore, $this->outboxCount());
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM ticket_bundle WHERE order_id = ?', [$orderId]));
    }

    public function testDeliverySendsOneEmailWithOneQrPerTicket(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        $orderId = $this->paidOrder($eventId, $tier, 3, 'Buyer@Example.com');

        $this->command(new DeliverTickets($orderId));

        self::assertEmailCount(1);
        $email = $this->sentEmail();
        self::assertEmailAttachmentCount($email, 3);
        self::assertSame(['ticket-1.png', 'ticket-2.png', 'ticket-3.png'], array_map(static fn($part): ?string => $part->getFilename(), $email->getAttachments()));
        foreach ($email->getAttachments() as $part) {
            self::assertSame('image/png', $part->getMediaType() . '/' . $part->getMediaSubtype());
            self::assertSame('inline', $part->getPreparedHeaders()->getHeaderBody('Content-Disposition'));
            self::assertStringStartsWith("\x89PNG", $part->getBody());
        }
        self::assertEmailAddressContains($email, 'to', 'buyer@example.com');
        self::assertEmailAddressContains($email, 'from', 'billets@stubandgo.test');
        self::assertSame('Vos billets — Concert', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Concert', $html);
        self::assertStringContainsString('La Cigale', $html);
        self::assertStringContainsString('Tarif', $html);
        foreach (['ticket-1.png', 'ticket-2.png', 'ticket-3.png'] as $image) {
            self::assertStringContainsString(\sprintf('<img src="cid:%s"', $image), $html);
        }
        self::assertTrue($this->bundles()->get(OrderId::fromString($orderId))->isDelivered());
    }

    public function testDeliveredBundleIsNotSentTwice(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        $orderId = $this->paidOrder($eventId, $tier);

        $this->command(new DeliverTickets($orderId));
        $deliveredAt = $this->bundles()->get(OrderId::fromString($orderId))->deliveredAt();
        $this->clock->sleep(60);
        $this->command(new DeliverTickets($orderId));

        self::assertEmailCount(1);
        self::assertEquals($deliveredAt, $this->bundles()->get(OrderId::fromString($orderId))->deliveredAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $deliveredAt);
    }

    public function testEmailShowsParisTime(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(startsAt: '2026-12-31T19:30:00+00:00');
        $orderId = $this->paidOrder($eventId, $tier);

        $this->command(new DeliverTickets($orderId));

        self::assertEmailHtmlBodyContains($this->sentEmail(), '31/12/2026 20:30');
    }

    public function testQrPayloadIsTheTokenOnly(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale();
        $orderId = $this->paidOrder($eventId, $tier, 2);
        $tokens = array_map(
            static fn($ticket): string => $ticket->admissionRight()->token()->toString(),
            $this->bundles()->get(OrderId::fromString($orderId))->tickets(),
        );

        $this->command(new DeliverTickets($orderId));

        self::assertSame($tokens, self::getContainer()->get(RecordingQrCodeRenderer::class)->payloads());
    }

    public function testDeliveringAnUnknownBundleFails(): void
    {
        $this->expectException(TicketBundleNotFoundException::class);

        $this->command(new DeliverTickets(OrderId::generate()->toString()));
    }

    private function paidOrder(string $eventId, string $tier, int $quantity = 1, string $buyerEmail = 'buyer@example.com'): string
    {
        $orderId = $this->placeOrder($eventId, [['priceTierId' => $tier, 'quantity' => $quantity]], $buyerEmail);
        $this->command(new PayOrder($orderId, self::ACCEPTED_CARD));

        return $orderId;
    }

    private function bundles(): TicketBundleRepositoryInterface
    {
        return self::getContainer()->get(TicketBundleRepositoryInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function outboxCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM outbox_message');
        self::assertIsInt($count);

        return $count;
    }

    private function sentEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }
}
