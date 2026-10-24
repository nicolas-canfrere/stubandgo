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
        $stamps = $this->dispatchUnwrapped($this->bus, $query)->all(HandledStamp::class);

        if (1 !== \count($stamps)) {
            throw new \LogicException(\sprintf('Query "%s" must be handled exactly once, got %d handled result(s).', $query::class, \count($stamps)));
        }

        /** @var HandledStamp $stamp */
        $stamp = $stamps[0];

        return $stamp->getResult();
    }
}
