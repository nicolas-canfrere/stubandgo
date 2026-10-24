<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

use App\Access\Core\Domain\ValueObject\TicketId;
use App\Shared\Identity\PriceTierId;

final readonly class Ticket
{
    public function __construct(
        private TicketId $id,
        private PriceTierId $priceTierId,
        private AdmissionRight $admissionRight,
    ) {
    }

    public function id(): TicketId
    {
        return $this->id;
    }

    public function priceTierId(): PriceTierId
    {
        return $this->priceTierId;
    }

    public function admissionRight(): AdmissionRight
    {
        return $this->admissionRight;
    }
}
