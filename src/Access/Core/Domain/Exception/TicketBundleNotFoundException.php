<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\Exception;

use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\OrderId;

final class TicketBundleNotFoundException extends AbstractNotFoundException
{
    public static function forOrder(OrderId $id): self
    {
        return new self(\sprintf('No ticket bundle for order "%s".', $id->toString()));
    }

    public function errorCode(): string
    {
        return 'ticket_bundle_not_found';
    }
}
