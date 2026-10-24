<?php

declare(strict_types=1);

namespace App\Shared\Bus;

/**
 * A read request sent through the query bus; TResult is the type its handler returns.
 *
 * @template TResult
 */
interface QueryInterface
{
}
