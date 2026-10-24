<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Exception\AbstractDomainException;

final class FixtureOtherException extends AbstractDomainException
{
    public function errorCode(): string
    {
        return 'fixture_other';
    }
}
