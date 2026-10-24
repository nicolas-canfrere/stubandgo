<?php

declare(strict_types=1);

namespace App\Catalog\Core\Application\Command;

use App\Shared\Bus\CommandInterface;

final readonly class CreateEvent implements CommandInterface
{
    /**
     * @param string                                                          $startsAt   ISO 8601 date-time with offset
     * @param list<array{id: string, label: string, priceCents: int}> $priceTiers
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $title,
        public string $description,
        public string $venue,
        public string $startsAt,
        public int $capacity,
        public array $priceTiers,
    ) {
    }
}
