<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxSchedule;
use App\Shared\Infrastructure\Outbox\RelayOutbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class OutboxScheduleTest extends TestCase
{
    public function testItSchedulesOneRelayEveryFiveSeconds(): void
    {
        $messages = new OutboxSchedule()->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        $recurring = $messages[array_key_first($messages)];

        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $next = $recurring->getTrigger()->getNextRunDate($now);
        self::assertEquals($now->modify('+5 seconds'), $next);

        $provided = iterator_to_array($recurring->getMessages(new MessageContext('default', 'id', $recurring->getTrigger(), $now)), false);
        self::assertEquals([new RelayOutbox()], $provided);
    }
}
