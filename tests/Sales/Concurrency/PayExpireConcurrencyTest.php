<?php

declare(strict_types=1);

namespace App\Tests\Sales\Concurrency;

use App\Sales\Core\Application\Command\PlaceOrder;
use App\Sales\Core\Application\Query\GetOrderStatus;
use App\Sales\Core\Application\Query\OrderStatusView;
use App\Shared\Identity\OrderId;
use App\Tests\Support\ParallelRunner;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
final class PayExpireConcurrencyTest extends AbstractSalesConcurrencyTestCase
{
    private const int ITERATIONS = 20;
    private const int QUANTITY = 2;
    private const int PAYMENT_DELAY_SECONDS = 900;

    /**
     * Each iteration places an order whose payment deadline falls 0 to 40 ms after the moment a PayOrder and an
     * ExpireOrder start in parallel, so that the payment, the late payment and the expiration all get to win
     * some races. Whatever the winner, exactly one transition applies: the order ends paid with its seats kept,
     * or expired with its seats released exactly once.
     */
    public function testConcurrentPayAndExpireApplyExactlyOneTransition(): void
    {
        ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 100);
        self::mockTime(new \DateTimeImmutable());
        $this->placeSingleLineOrder($eventId, $tier);
        $paid = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $orderId = OrderId::generate()->toString();
            $deadlineOffsetSeconds = ($i % 5) * 10 / 1000;

            $outcomes = ParallelRunner::run(
                [
                    ['test:sales:dispatch', 'pay-order', $orderId, '4242424242424242'],
                    ['test:sales:dispatch', 'expire-order', $orderId],
                ],
                function () use ($orderId, $eventId, $tier, $deadlineOffsetSeconds): void {
                    self::mockTime(new \DateTimeImmutable(\sprintf('@%.6F', microtime(true) - self::PAYMENT_DELAY_SECONDS + $deadlineOffsetSeconds)));
                    $this->command(new PlaceOrder($orderId, $eventId, 'buyer@example.com', [['priceTierId' => $tier, 'quantity' => self::QUANTITY]]));
                },
            );

            $status = $this->statusOf($orderId);
            $context = \sprintf('Iteration %d: status %s, outcomes %s.', $i, $status, json_encode($outcomes, \JSON_THROW_ON_ERROR));

            if ('paid' === $status) {
                ++$paid;
                self::assertSame(['ok', 'ok'], $outcomes, $context);
            } else {
                self::assertSame('expired', $status, $context);
                self::assertContains($outcomes[0], ['ok', 'OrderNotPendingException'], $context);
                self::assertSame('ok', $outcomes[1], $context);
            }

            self::assertSame(1 + self::QUANTITY * $paid, $this->taken($eventId), $context);
        }
    }

    private function statusOf(string $orderId): string
    {
        $view = $this->ask(new GetOrderStatus($orderId));
        self::assertInstanceOf(OrderStatusView::class, $view);

        return $view->status;
    }
}
