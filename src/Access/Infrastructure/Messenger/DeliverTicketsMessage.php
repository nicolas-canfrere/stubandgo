<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Messenger;

use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\OrderId;
use App\Shared\Infrastructure\Outbox\AsyncMessageInterface;

final readonly class DeliverTicketsMessage implements AsyncMessageInterface
{
    /**
     * @throws InvalidValueException when the order id is not a ULID; the message never contains the input
     */
    public function __construct(public string $orderId)
    {
        try {
            OrderId::fromString($orderId);
        } catch (InvalidValueException) {
            throw new InvalidValueException('The order id of a ticket delivery must be a ULID.');
        }
    }
}
