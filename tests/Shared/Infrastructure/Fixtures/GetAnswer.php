<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<int>
 */
final readonly class GetAnswer implements QueryInterface
{
}
