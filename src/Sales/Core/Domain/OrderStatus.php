<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
