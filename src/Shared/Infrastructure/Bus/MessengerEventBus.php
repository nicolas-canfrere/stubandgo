<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Bus\EventBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEventBus implements EventBusInterface
{
    use UnwrapsHandlerFailureTrait;

    public function __construct(#[Target('event.bus')] private MessageBusInterface $bus)
    {
    }

    public function publish(object ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatchUnwrapped($this->bus, $event);
        }
    }
}
