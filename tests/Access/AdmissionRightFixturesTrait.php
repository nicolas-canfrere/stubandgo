<?php

declare(strict_types=1);

namespace App\Tests\Access;

use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Infrastructure\RandomTicketTokenGenerator;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;

/**
 * Seeds an unused admission right straight through the bundle repository: no order, no outbox message.
 */
trait AdmissionRightFixturesTrait
{
    /**
     * @return array{orderId: string, eventId: string, token: string}
     */
    protected function issueAdmissionRight(?string $eventId = null): array
    {
        $bundle = TicketBundle::issue(
            OrderId::generate(),
            null === $eventId ? EventId::generate() : EventId::fromString($eventId),
            EmailAddress::fromString('buyer@example.com'),
            [new OrderLine(PriceTierId::generate(), Quantity::of(1))],
            new RandomTicketTokenGenerator(),
        );
        self::getContainer()->get(TicketBundleRepositoryInterface::class)->save($bundle);

        return [
            'orderId' => $bundle->orderId()->toString(),
            'eventId' => $bundle->eventId()->toString(),
            'token' => $bundle->tickets()[0]->admissionRight()->token()->toString(),
        ];
    }

    /**
     * A well-formed token that no admission right carries.
     */
    protected function unknownToken(): string
    {
        return new RandomTicketTokenGenerator()->generate()->toString();
    }
}
