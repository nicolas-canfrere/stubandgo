<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure\Messenger;

use App\Access\Core\Application\Command\DeliverTickets;
use App\Access\Infrastructure\Messenger\DeliverTicketsMessage;
use App\Access\Infrastructure\Messenger\DeliverTicketsMessageHandler;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\CommandInterface;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Outbox\AsyncMessageInterface;
use PHPUnit\Framework\TestCase;

final class DeliverTicketsMessageTest extends TestCase
{
    public function testMessageIsAsynchronousAndKeepsTheOrderId(): void
    {
        $orderId = OrderId::generate()->toString();

        $message = new DeliverTicketsMessage($orderId);

        self::assertTrue(new \ReflectionClass($message)->implementsInterface(AsyncMessageInterface::class));
        self::assertSame($orderId, $message->orderId);
    }

    public function testInvalidOrderIdIsRejectedWithoutEchoingIt(): void
    {
        try {
            new DeliverTicketsMessage('secret-value');
            self::fail('An invalid order id must be rejected.');
        } catch (InvalidValueException $exception) {
            self::assertSame('The order id of a ticket delivery must be a ULID.', $exception->getMessage());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function testHandlerDispatchesTheDeliverTicketsCommand(): void
    {
        $orderId = OrderId::generate()->toString();
        $bus = new class implements CommandBusInterface {
            /** @var list<CommandInterface> */
            public array $dispatched = [];

            public function dispatch(CommandInterface $command): void
            {
                $this->dispatched[] = $command;
            }
        };

        new DeliverTicketsMessageHandler($bus)(new DeliverTicketsMessage($orderId));

        self::assertEquals([new DeliverTickets($orderId)], $bus->dispatched);
    }
}
