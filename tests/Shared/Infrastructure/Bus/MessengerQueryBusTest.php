<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Bus;

use App\Shared\Infrastructure\Bus\MessengerQueryBus;
use App\Tests\Shared\Infrastructure\Fixtures\GetAnswer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBusTest extends TestCase
{
    public function testQueryWithoutHandledStampIsRejected(): void
    {
        $query = new GetAnswer();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope($query));

        $this->expectException(\LogicException::class);

        new MessengerQueryBus($bus)->ask($query);
    }
}
