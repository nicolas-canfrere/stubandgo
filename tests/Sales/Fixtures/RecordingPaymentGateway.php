<?php

declare(strict_types=1);

namespace App\Tests\Sales\Fixtures;

use App\Sales\Core\Domain\PaymentGatewayInterface;
use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Shared\Identity\OrderId;
use Money\Money;

/**
 * Decorates the payment gateway in the test environment to count the charges; the card number is not kept.
 */
final class RecordingPaymentGateway implements PaymentGatewayInterface
{
    /** @var list<array{orderId: string, amountCents: string}> */
    private array $charges = [];

    public function __construct(private readonly PaymentGatewayInterface $inner)
    {
    }

    public function charge(OrderId $orderId, Money $amount, CardNumber $cardNumber): PaymentOutcome
    {
        $this->charges[] = ['orderId' => $orderId->toString(), 'amountCents' => $amount->getAmount()];

        return $this->inner->charge($orderId, $amount, $cardNumber);
    }

    /**
     * @return list<array{orderId: string, amountCents: string}>
     */
    public function charges(): array
    {
        return $this->charges;
    }
}
