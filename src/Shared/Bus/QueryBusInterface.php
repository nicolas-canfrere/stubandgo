<?php

declare(strict_types=1);

namespace App\Shared\Bus;

interface QueryBusInterface
{
    /**
     * @template TResult
     *
     * @param QueryInterface<TResult> $query
     *
     * @return TResult
     */
    public function ask(QueryInterface $query): mixed;
}
