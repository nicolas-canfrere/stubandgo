<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

enum PaymentOutcome
{
    case Accepted;
    case Declined;
}
