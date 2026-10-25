<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Catalog\Core\Domain\Event;
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

    /**
     * The tiers of a revision: a tier without identifier gets a new one, an identifier must be one of a tier of the event.
     *
     * @param list<array{id: string|null, label: string, priceCents: int}> $priceTiers
     *
     * @return list<PriceTier>
     */
    public static function revisedPriceTiers(array $priceTiers, Event $event): array
    {
        $existing = array_map(static fn(PriceTier $tier): string => $tier->id()->toString(), $event->priceTiers());

        return self::priceTiers(array_map(
            static fn(array $tier): array => ['id' => self::revisedTierId($tier['id'], $existing), 'label' => $tier['label'], 'priceCents' => $tier['priceCents']],
            $priceTiers,
        ));
    }

    /**
     * @param list<string> $existing identifiers of the tiers of the event
     */
    private static function revisedTierId(?string $id, array $existing): string
    {
        if (null === $id) {
            return PriceTierId::generate()->toString();
        }

        if (!\in_array(PriceTierId::fromString($id)->toString(), $existing, true)) {
            throw new InvalidValueException('A price tier to keep is not a tier of the event.');
        }

        return $id;
    }
}
