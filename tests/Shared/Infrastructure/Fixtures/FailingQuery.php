<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<never>
 */
final readonly class FailingQuery implements QueryInterface
{
}
