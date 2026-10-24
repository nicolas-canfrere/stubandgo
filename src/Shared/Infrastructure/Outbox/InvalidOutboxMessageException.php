<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

final class InvalidOutboxMessageException extends \RuntimeException
{
    public static function forClass(string $class): self
    {
        return new self(\sprintf('Outbox message class "%s" does not exist or is not an async message.', $class));
    }
}
