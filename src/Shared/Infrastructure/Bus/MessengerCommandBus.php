<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\CommandInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerCommandBus implements CommandBusInterface
{
    use UnwrapsHandlerFailureTrait;

    public function __construct(#[Target('command.bus')] private MessageBusInterface $bus)
    {
    }

    public function dispatch(CommandInterface $command): void
    {
        $this->dispatchUnwrapped($this->bus, $command);
    }
}
