<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures\Http;

use App\Tests\Shared\Infrastructure\Fixtures\FixtureConflictException;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureNotFoundException;
use App\Tests\Shared\Infrastructure\Fixtures\FixtureOtherException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FixtureController
{
    #[Route('/_test/not-found', methods: 'GET')]
    public function notFound(): Response
    {
        throw new FixtureNotFoundException('Thing 42 not found.');
    }

    #[Route('/_test/conflict', methods: 'GET')]
    public function conflict(): Response
    {
        throw new FixtureConflictException('Thing 42 conflicts.');
    }

    #[Route('/_test/domain', methods: 'GET')]
    public function domain(): Response
    {
        throw new FixtureOtherException('Thing 42 is invalid.');
    }

    #[Route('/_test/crash', methods: 'GET')]
    public function crash(): Response
    {
        throw new \RuntimeException('crash');
    }
}
