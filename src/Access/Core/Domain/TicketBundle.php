<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\TicketId;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;

/**
 * The tickets issued for one paid order, delivered to the buyer once.
 */
final class TicketBundle
{
    /**
     * @param list<Ticket> $tickets
     */
    private function __construct(
        private readonly OrderId $orderId,
        private readonly EventId $eventId,
        private readonly EmailAddress $buyerEmail,
        private readonly array $tickets,
        private ?\DateTimeImmutable $deliveredAt,
    ) {
    }

    /**
     * Issues one ticket, with one admission right for the event, per unit of each line.
     *
     * @param list<OrderLine> $lines
     *
     * @throws InvalidValueException when there is no line
     */
    public static function issue(
        OrderId $orderId,
        EventId $eventId,
        EmailAddress $buyerEmail,
        array $lines,
        TicketTokenGeneratorInterface $tokenGenerator,
    ): self {
        if ([] === $lines) {
            throw new InvalidValueException('A ticket bundle needs at least one order line.');
        }

        $tickets = [];
        foreach ($lines as $line) {
            for ($unit = 0; $unit < $line->quantity->toInt(); ++$unit) {
                $tickets[] = new Ticket(
                    TicketId::generate(),
                    $line->priceTierId,
                    new AdmissionRight($tokenGenerator->generate(), $eventId),
                );
            }
        }

        return new self($orderId, $eventId, $buyerEmail, $tickets, null);
    }

    /**
     * Rebuilds a persisted bundle: the issuing checks already passed.
     *
     * @param list<Ticket> $tickets
     */
    public static function reconstitute(
        OrderId $orderId,
        EventId $eventId,
        EmailAddress $buyerEmail,
        array $tickets,
        ?\DateTimeImmutable $deliveredAt,
    ): self {
        return new self($orderId, $eventId, $buyerEmail, $tickets, self::utc($deliveredAt));
    }

    /**
     * Records the delivery. Delivery is at least once: a second call keeps the first date.
     */
    public function markDelivered(\DateTimeImmutable $now): void
    {
        $this->deliveredAt ??= self::utc($now);
    }

    public function isDelivered(): bool
    {
        return null !== $this->deliveredAt;
    }

    public function orderId(): OrderId
    {
        return $this->orderId;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function buyerEmail(): EmailAddress
    {
        return $this->buyerEmail;
    }

    /**
     * @return list<Ticket>
     */
    public function tickets(): array
    {
        return $this->tickets;
    }

    public function deliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    private static function utc(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'));
    }
}
