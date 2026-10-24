<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

trait UnwrapsHandlerFailureTrait
{
    /**
     * Dispatches the message and rethrows the original exception instead of the HandlerFailedException
     * that wraps it (the first one when several handlers failed).
     */
    private function dispatchUnwrapped(MessageBusInterface $bus, object $message): Envelope
    {
        try {
            return $bus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            throw array_first($exception->getWrappedExceptions()) ?? $exception;
        }
    }
}
