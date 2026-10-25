<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure\Messenger;

use App\Access\Infrastructure\Messenger\DeliverTicketsMessage;
use App\Shared\Identity\OrderId;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A ticket delivery that keeps failing (here: no bundle for the order) is retried with a growing delay, then kept
 * in the failure transport instead of being dropped, so that messenger:failed:retry can resend it.
 */
final class TicketDeliveryFailureTransportTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const int RETRIES = 10;
    private const int MAX_DELAY_MS = 3_600_000;

    private MockClock $clock;
    private InMemoryTransport $async;
    private InMemoryTransport $failed;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable('2026-10-24T12:00:00+00:00'));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
        $this->async = $this->transport('messenger.transport.async');
        $this->failed = $this->transport('messenger.transport.failed');
    }

    public function testDeliveryThatKeepsFailingEndsInTheFailureTransportAfterTenRetries(): void
    {
        $orderId = OrderId::generate()->toString();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeliverTicketsMessage($orderId));

        for ($attempt = 1; $attempt <= self::RETRIES; ++$attempt) {
            $this->consumeOneMessage();
            self::assertSame([], $this->failed->getSent(), 'Attempt ' . $attempt . ' must be retried.');
        }
        $this->consumeOneMessage();

        $failed = $this->failed->getSent();
        self::assertCount(1, $failed);
        $message = $failed[0]->getMessage();
        self::assertInstanceOf(DeliverTicketsMessage::class, $message);
        self::assertSame($orderId, $message->orderId);
        self::assertSame([], [...$this->async->get()]);
        $this->assertRetryDelays();
    }

    /**
     * The first retry waits about a second (10 % jitter), each next one three times longer, capped at one hour.
     */
    private function assertRetryDelays(): void
    {
        $delays = array_map(
            static fn(Envelope $envelope): ?int => $envelope->last(DelayStamp::class)?->getDelay(),
            \array_slice($this->async->getSent(), 1),
        );

        self::assertCount(self::RETRIES, $delays);
        self::assertGreaterThanOrEqual(900, $delays[0]);
        self::assertLessThanOrEqual(1100, $delays[0]);
        self::assertGreaterThanOrEqual(2700, $delays[1]);
        self::assertLessThanOrEqual(3300, $delays[1]);
        self::assertSame([self::MAX_DELAY_MS, self::MAX_DELAY_MS], \array_slice($delays, -2));
    }

    private function consumeOneMessage(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('messenger:consume'));
        $tester->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 1, '--no-reset' => null]);
        $this->clock->sleep(2 * self::MAX_DELAY_MS / 1000);
    }

    private function transport(string $id): InMemoryTransport
    {
        $transport = self::getContainer()->get($id);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
