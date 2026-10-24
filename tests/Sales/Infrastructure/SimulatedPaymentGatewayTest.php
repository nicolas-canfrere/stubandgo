<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure;

use App\Sales\Core\Domain\PaymentOutcome;
use App\Sales\Core\Domain\ValueObject\CardNumber;
use App\Sales\Infrastructure\SimulatedPaymentGateway;
use App\Shared\Identity\OrderId;
use Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulatedPaymentGatewayTest extends TestCase
{
    #[DataProvider('cards')]
    public function testItAcceptsOnlyTheTestCard(string $cardNumber, PaymentOutcome $expected): void
    {
        self::assertSame($expected, new SimulatedPaymentGateway()->charge(OrderId::generate(), Money::EUR(2500), CardNumber::fromString($cardNumber)));
    }

    /**
     * @return iterable<string, array{string, PaymentOutcome}>
     */
    public static function cards(): iterable
    {
        yield 'accepted test card' => ['4242424242424242', PaymentOutcome::Accepted];
        yield 'accepted test card with spaces' => ['4242 4242 4242 4242', PaymentOutcome::Accepted];
        yield 'declined test card' => ['4000000000000002', PaymentOutcome::Declined];
        yield 'any other card' => ['5555555555554444', PaymentOutcome::Declined];
    }
}
