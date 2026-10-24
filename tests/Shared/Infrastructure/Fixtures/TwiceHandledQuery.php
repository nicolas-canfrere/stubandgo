<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Fixtures;

use App\Shared\Bus\QueryInterface;

/**
 * @implements QueryInterface<string>
 */
final readonly class TwiceHandledQuery implements QueryInterface
{
}
