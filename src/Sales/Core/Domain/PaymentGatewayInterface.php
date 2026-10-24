<?php

declare(strict_types=1);

namespace App\Sales\Core\Domain;

use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Shared\Identity\OrderId;
use Money\Money;

interface PaymentGatewayInterface
{
    public function charge(OrderId $orderId, Money $amount, CardNumber $cardNumber): PaymentOutcome;
}
