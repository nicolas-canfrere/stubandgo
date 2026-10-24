<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Exception\AbstractConflictException;

final class FixtureConflictException extends AbstractConflictException
{
    public function errorCode(): string
    {
        return 'fixture_conflict';
    }
}
