<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain\Exception;

use App\Shared\Exception\AbstractUpstreamFailureException;

final class IdentityProviderUnavailableException extends AbstractUpstreamFailureException
{
    public static function unreachable(?\Throwable $previous = null): self
    {
        return new self('The identity provider is unavailable.', previous: $previous);
    }
}
