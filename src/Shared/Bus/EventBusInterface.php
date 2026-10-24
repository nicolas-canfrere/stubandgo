<?php

declare(strict_types=1);

namespace App\Shared\Bus;

interface EventBusInterface
{
    public function publish(object ...$events): void;
}
