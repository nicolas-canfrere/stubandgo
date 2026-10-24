<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Exception\AbstractNotFoundException;

final class FixtureNotFoundException extends AbstractNotFoundException
{
    public function errorCode(): string
    {
        return 'fixture_not_found';
    }
}
