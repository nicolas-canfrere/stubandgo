<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Observability\WorkerHeartbeat;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\RelayOutbox;
use App\Shared\Infrastructure\Outbox\RelayOutboxHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class RelayOutboxHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private WorkerHeartbeat $heartbeat;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->heartbeat = $container->get(WorkerHeartbeat::class);
        $container->get(Connection::class)->executeStatement('DELETE FROM worker_heartbeat');
    }

    public function testRelayRunUpdatesWorkerHeartbeat(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-10-25T10:00:00+00:00'));
        $relay = $this->createMock(OutboxRelay::class);
        $relay->expects(self::once())->method('relayBatch')->with(50)->willReturn(3);

        (new RelayOutboxHandler($relay, $this->heartbeat))(new RelayOutbox());

        self::assertSame('2026-10-25T10:00:00+00:00', $this->heartbeat->lastSeenAt()?->format(\DATE_ATOM));
    }

    public function testFailingRelayRunLeavesNoHeartbeat(): void
    {
        $relay = $this->createStub(OutboxRelay::class);
        $relay->method('relayBatch')->willThrowException(new \RuntimeException('boom'));

        try {
            (new RelayOutboxHandler($relay, $this->heartbeat))(new RelayOutbox());
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
            self::assertNull($this->heartbeat->lastSeenAt());
        }
    }
}
