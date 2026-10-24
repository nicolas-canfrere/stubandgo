<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure;

use App\Sales\Core\Domain\PaymentGatewayInterface;
use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Shared\Identity\OrderId;
use Money\Money;

/**
 * Simulated payment service provider: only the test card 4242 4242 4242 4242 is accepted, every other card
 * (4000 0000 0000 0002 in the documentation) is declined. The card number is neither stored nor logged.
 */
final readonly class SimulatedPaymentGateway implements PaymentGatewayInterface
{
    private const string ACCEPTED_CARD = '4242424242424242';

    public function charge(OrderId $orderId, Money $amount, CardNumber $cardNumber): PaymentOutcome
    {
        return self::ACCEPTED_CARD === $cardNumber->digits() ? PaymentOutcome::Accepted : PaymentOutcome::Declined;
    }
}
