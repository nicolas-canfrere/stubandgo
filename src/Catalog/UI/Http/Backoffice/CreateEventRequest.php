<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Shared\Identity\PriceTierId;
use OpenApi\Attributes as OA;

/**
 * Structure of the event body of the creation: the format of every field is checked by the domain.
 */
final readonly class CreateEventRequest
{
    /**
     * @param list<NewPriceTierRequest> $priceTiers
     */
    public function __construct(
        #[OA\Property(example: 'Soirée Rock')]
        public string $title,
        #[OA\Property(example: 'Trois groupes sur scène.')]
        public string $description,
        #[OA\Property(example: 'La Cigale')]
        public string $venue,
        #[OA\Property(description: 'Start as an ISO 8601 date-time with a UTC offset; it must be in the future.', example: '2026-11-20T20:00:00+01:00')]
        public string $startsAt,
        #[OA\Property(description: 'Number of seats.', example: 200)]
        public int $capacity,
        #[OA\Property(description: 'The price tiers of the event; each one gets a new identifier.')]
        public array $priceTiers,
    ) {
    }

    /**
     * The tiers as the command carries them, each with a new identifier.
     *
     * @return list<array{id: string, label: string, priceCents: int}>
     */
    public function commandPriceTiers(): array
    {
        return array_map(
            static fn(NewPriceTierRequest $tier): array => ['id' => PriceTierId::generate()->toString(), 'label' => $tier->label, 'priceCents' => $tier->priceCents],
            $this->priceTiers,
        );
    }
}
