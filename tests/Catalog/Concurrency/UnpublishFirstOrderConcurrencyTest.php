<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Concurrency;

use App\Shared\Identity\OrderId;
use App\Tests\Sales\Concurrency\AbstractSalesConcurrencyTestCase;
use App\Tests\Support\ParallelRunner;
use PHPUnit\Framework\Attributes\Group;

/**
 * The unpublication loads the event FOR UPDATE and the sale terms read by PlaceOrder take a FOR KEY SHARE lock on
 * the same row: one waits for the other, so an unpublished event never sells and a locked event is never unpublished.
 */
#[Group('concurrency')]
final class UnpublishFirstOrderConcurrencyTest extends AbstractSalesConcurrencyTestCase
{
    private const int ITERATIONS = 30;
    private const int FIRST_ORDER_ITERATIONS = 10;

    public function testUnpublicationAndFirstOrderNeverBothSucceed(): void
    {
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);

            $outcomes = ParallelRunner::run([
                ['test:catalog:dispatch', 'unpublish-event', $eventId],
                ['test:sales:dispatch', 'place-order', OrderId::generate()->toString(), $eventId, $tier, '1'],
            ]);

            $state = $this->stateOf($eventId);
            $context = \sprintf('Iteration %d: outcomes %s, state %s.', $i, json_encode($outcomes, \JSON_THROW_ON_ERROR), json_encode($state, \JSON_THROW_ON_ERROR));

            if ('ok' === $outcomes[0]) {
                self::assertSame(['ok', 'EventNotOnSaleException'], $outcomes, $context);
                self::assertSame(['status' => 'draft', 'locked' => false, 'taken' => 0, 'orders' => 0], $state, $context);
            } else {
                self::assertSame(['EventLockedException', 'ok'], $outcomes, $context);
                self::assertSame(['status' => 'published', 'locked' => true, 'taken' => 1, 'orders' => 1], $state, $context);
            }
        }
    }

    /**
     * Two first orders both hold the key-share lock while one of them sets the lock flag of the event: a FOR SHARE
     * lock would deadlock them, FOR KEY SHARE lets both through.
     */
    public function testSimultaneousFirstOrdersBothSucceedWithoutDeadlock(): void
    {
        for ($i = 0; $i < self::FIRST_ORDER_ITERATIONS; ++$i) {
            ['eventId' => $eventId, 'priceTierIds' => [$tier]] = $this->eventOnSale(capacity: 10);

            $outcomes = ParallelRunner::run([
                ['test:sales:dispatch', 'place-order', OrderId::generate()->toString(), $eventId, $tier, '1'],
                ['test:sales:dispatch', 'place-order', OrderId::generate()->toString(), $eventId, $tier, '1'],
            ]);

            self::assertSame(['ok', 'ok'], $outcomes, \sprintf('Iteration %d.', $i));
            self::assertSame(['status' => 'published', 'locked' => true, 'taken' => 2, 'orders' => 2], $this->stateOf($eventId), \sprintf('Iteration %d.', $i));
        }
    }

    /**
     * @return array{status: mixed, locked: mixed, taken: mixed, orders: mixed}
     */
    private function stateOf(string $eventId): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT e.status, e.locked, s.taken, (SELECT COUNT(*) FROM sales_order o WHERE o.event_id = e.id) AS orders FROM event e JOIN seat_stock s ON s.event_id = e.id WHERE e.id = ?',
            [$eventId],
        );
        \assert(\is_array($row));

        return ['status' => $row['status'], 'locked' => $row['locked'], 'taken' => $row['taken'], 'orders' => $row['orders']];
    }
}
