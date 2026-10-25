<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\PriceTier;
use App\Catalog\Core\Domain\ValueObject\PriceTierLabel;
use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;
use Money\Money;

/**
 * Rebuilds the value objects of the event fields carried by the creation and the revision commands.
 */
final class EventInput
{
    private function __construct()
    {
    }

    public static function startsAt(string $startsAt): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $startsAt);

        if (false === $date || false !== \DateTimeImmutable::getLastErrors()) {
            throw new InvalidValueException('Event start must be an ISO 8601 date-time with a UTC offset.');
        }

        return $date;
    }

    /**
     * @param list<array{id: string, label: string, priceCents: int}> $priceTiers
     *
     * @return list<PriceTier>
     */
    public static function priceTiers(array $priceTiers): array
    {
        return array_map(
            static fn(array $tier): PriceTier => new PriceTier(
                PriceTierId::fromString($tier['id']),
                PriceTierLabel::fromString($tier['label']),
                Money::EUR($tier['priceCents']),
            ),
            $priceTiers,
        );
    }
}
