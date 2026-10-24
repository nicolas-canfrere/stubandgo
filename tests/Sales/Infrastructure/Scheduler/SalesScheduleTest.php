<?php

declare(strict_types=1);

namespace App\Tests\Sales\Infrastructure\Scheduler;

use App\Sales\Infrastructure\Scheduler\ExpireOverdueOrders;
use App\Sales\Infrastructure\Scheduler\SalesSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class SalesScheduleTest extends TestCase
{
    public function testItExpiresOverdueOrdersEveryMinute(): void
    {
        $messages = new SalesSchedule()->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        $recurring = $messages[array_key_first($messages)];

        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        self::assertEquals($now->modify('+1 minute'), $recurring->getTrigger()->getNextRunDate($now));

        $provided = iterator_to_array($recurring->getMessages(new MessageContext('sales', 'id', $recurring->getTrigger(), $now)), false);
        self::assertEquals([new ExpireOverdueOrders()], $provided);
    }
}
