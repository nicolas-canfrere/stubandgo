<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\RelayOutbox;
use App\Shared\Infrastructure\Outbox\RelayOutboxHandler;
use PHPUnit\Framework\TestCase;

final class RelayOutboxHandlerTest extends TestCase
{
    public function testItRelaysABatch(): void
    {
        $relay = $this->createMock(OutboxRelay::class);
        $relay->expects(self::once())->method('relayBatch')->with(50)->willReturn(3);

        new RelayOutboxHandler($relay)(new RelayOutbox());
    }
}
