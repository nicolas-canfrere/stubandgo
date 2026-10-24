<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('sales')]
final readonly class SalesSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every('1 minute', new ExpireOverdueOrders()));
    }
}
