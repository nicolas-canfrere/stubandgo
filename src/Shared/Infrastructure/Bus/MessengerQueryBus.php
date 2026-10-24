<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Bus\QueryBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class MessengerQueryBus implements QueryBusInterface
{
    use UnwrapsHandlerFailureTrait;

    public function __construct(#[Target('query.bus')] private MessageBusInterface $bus)
    {
    }

    public function ask(object $query): mixed
    {
        return $this->dispatchUnwrapped($this->bus, $query)->last(HandledStamp::class)?->getResult();
    }
}
