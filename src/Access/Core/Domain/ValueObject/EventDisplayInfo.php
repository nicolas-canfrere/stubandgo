<?php

declare(strict_types=1);

namespace App\Access\Core\Domain\ValueObject;

use App\Shared\Exception\InvalidValueException;
use App\Shared\Identity\PriceTierId;

/**
 * What a ticket email shows about the event: display data only, never used for a decision.
 */
final readonly class EventDisplayInfo
{
    private \DateTimeImmutable $startsAt;

    /**
     * @param array<string, string> $priceTierLabels price tier label by price tier id
     */
    private function __construct(
        private string $title,
        private string $venue,
        \DateTimeImmutable $startsAt,
        private array $priceTierLabels,
    ) {
        $this->startsAt = $startsAt->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, string> $priceTierLabels price tier label by price tier id
     */
    public static function of(string $title, string $venue, \DateTimeImmutable $startsAt, array $priceTierLabels): self
    {
        if ('' === trim($title) || '' === trim($venue)) {
            throw new InvalidValueException('Event title and venue must not be blank.');
        }

        return new self($title, $venue, $startsAt, $priceTierLabels);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function venue(): string
    {
        return $this->venue;
    }

    public function startsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function labelOf(PriceTierId $id): string
    {
        return $this->priceTierLabels[$id->toString()]
            ?? throw new InvalidValueException(\sprintf('No label for price tier "%s".', $id->toString()));
    }
}
