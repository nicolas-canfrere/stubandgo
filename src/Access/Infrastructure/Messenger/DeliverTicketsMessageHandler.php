<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Messenger;

use App\Access\Core\Application\Command\DeliverTickets;
use App\Shared\Bus\CommandBusInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'async.bus')]
final readonly class DeliverTicketsMessageHandler
{
    public function __construct(private CommandBusInterface $commandBus)
    {
    }

    public function __invoke(DeliverTicketsMessage $message): void
    {
        $this->commandBus->dispatch(new DeliverTickets($message->orderId));
    }
}
