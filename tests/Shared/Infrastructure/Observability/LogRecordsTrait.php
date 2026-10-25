<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability;

use Monolog\Handler\TestHandler;
use Monolog\Level;
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

    /**
     * A rejected request (4xx) is logged once by Symfony's error listener, as a warning, and nothing reaches error.
     */
    private static function assertLoggedAsRejection(): void
    {
        self::assertSame([], self::recordsAtOrAboveError());
        self::assertSame([Level::Warning], self::uncaughtExceptionLevels());
    }

    /**
     * @return list<string> level and message of every record at error or above
     */
    private static function recordsAtOrAboveError(): array
    {
        return array_values(array_map(
            static fn(LogRecord $record): string => $record->level->getName() . ': ' . $record->message,
            array_filter(self::logHandler()->getRecords(), static fn(LogRecord $record): bool => $record->level->value >= Level::Error->value),
        ));
    }

    /**
     * @return list<Level> levels at which Symfony's error listener logged an uncaught exception
     */
    private static function uncaughtExceptionLevels(): array
    {
        return array_values(array_map(
            static fn(LogRecord $record): Level => $record->level,
            array_filter(self::logHandler()->getRecords(), static fn(LogRecord $record): bool => str_starts_with($record->message, 'Uncaught PHP Exception ')),
        ));
    }
}
