<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Core\Application\Fixtures;

use App\Shared\Event\EventPublished;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final class EventPublishedRecorder
{
    /** @var list<EventPublished> */
    private array $recorded = [];

    public function __invoke(EventPublished $event): void
    {
        $this->recorded[] = $event;
    }

    /**
     * @return list<EventPublished>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
