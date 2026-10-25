<?php

declare(strict_types=1);

namespace App\Shared\Exception;

/**
 * A failure of a system we depend on, reported as a bad gateway; its error code is the snake-cased class name without its Exception suffix.
 */
abstract class AbstractUpstreamFailureException extends AbstractDomainException
{
    final public function errorCode(): string
    {
        preg_match_all('/[A-Z][a-z0-9]*/', new \ReflectionClass($this)->getShortName(), $words);

        // The last word is the "Exception" suffix.
        return strtolower(implode('_', \array_slice($words[0], 0, -1)));
    }
}
