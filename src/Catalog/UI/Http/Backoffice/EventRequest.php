<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Backoffice;

use App\Shared\Identity\PriceTierId;
use OpenApi\Attributes as OA;

/**
 * Structure of the event body of the creation and the revision: the format of every field is checked by the domain.
 */
final readonly class EventRequest
{
    /**
     * @param list<PriceTierRequest> $priceTiers
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
        #[OA\Property(description: 'The complete list of price tiers: a tier missing from it is deleted on revision.')]
        public array $priceTiers,
    ) {
    }

    /**
     * The tiers as the commands carry them: a tier without identifier gets a new one.
     *
     * @return list<array{id: string, label: string, priceCents: int}>
     */
    public function commandPriceTiers(): array
    {
        return array_map(
            static fn(PriceTierRequest $tier): array => ['id' => $tier->id ?? PriceTierId::generate()->toString(), 'label' => $tier->label, 'priceCents' => $tier->priceCents],
            $this->priceTiers,
        );
    }
}
