<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final readonly class OutboxSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every('5 seconds', new RelayOutbox()));
    }
}
