<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * Reads what the test-environment Monolog handler recorded in the current kernel.
 */
trait LogRecordsTrait
{
    private static function logHandler(): TestHandler
    {
        return self::getContainer()->get('monolog.handler.main');
    }

    /**
     * @return list<LogRecord>
     */
    private static function logRecords(?string $message = null): array
    {
        return array_values(array_filter(
            self::logHandler()->getRecords(),
            static fn(LogRecord $record): bool => null === $message || $record->message === $message,
        ));
    }
}
