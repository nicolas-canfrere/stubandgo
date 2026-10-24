<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Infrastructure\Messenger\DeliverTicketsMessage;
use App\Access\Infrastructure\OutboxTicketDeliveryRequester;
use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Outbox\AsyncMessageInterface;
use App\Shared\Infrastructure\Outbox\OutboxWriterInterface;
use PHPUnit\Framework\TestCase;

final class OutboxTicketDeliveryRequesterTest extends TestCase
{
    public function testWritesADeliverTicketsMessageToTheOutbox(): void
    {
        $orderId = OrderId::generate();
        $writer = new class implements OutboxWriterInterface {
            /** @var list<AsyncMessageInterface> */
            public array $stored = [];

            public function store(AsyncMessageInterface $message): void
            {
                $this->stored[] = $message;
            }
        };

        new OutboxTicketDeliveryRequester($writer)->request($orderId);

        self::assertEquals([new DeliverTicketsMessage($orderId->toString())], $writer->stored);
    }
}
