<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers 401 with a Bearer challenge (RFC 6750) when the access token is missing or invalid.
 *
 * It throws instead of building the response, so that ProblemDetailsListener renders it as problem details like
 * every other error. The authentication exception is not chained: the security exception listener would catch it
 * again and restart the authentication.
 */
final class BearerTokenChallenge implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        throw new UnauthorizedHttpException('Bearer', 'An access token is required.');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        throw new UnauthorizedHttpException('Bearer error="invalid_token"', 'The access token is invalid.');
    }
}
