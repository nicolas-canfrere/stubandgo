<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Keycloak;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Access token of the API's Keycloak service account (client credentials), kept until shortly before it expires.
 */
final class ServiceAccountTokenProvider
{
    private const string TOKEN_PATH = '/realms/stubandgo/protocol/openid-connect/token';
    /** Seconds before the announced expiry from which the token is no longer reused. */
    private const int SAFETY_MARGIN = 30;

    private ?string $token = null;
    private int $reusableUntil = 0;

    public function __construct(
        #[Autowire(service: 'keycloak.admin.client')]
        private readonly HttpClientInterface $client,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'KEYCLOAK_ADMIN_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'KEYCLOAK_ADMIN_CLIENT_SECRET')]
        private readonly string $clientSecret,
    ) {
    }

    /**
     * @throws IdentityProviderUnavailableException
     */
    public function token(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if (null !== $this->token && $now < $this->reusableUntil) {
            return $this->token;
        }

        $payload = $this->requestPayload();
        $token = $payload['access_token'] ?? null;
        $lifetime = $payload['expires_in'] ?? null;

        if (!\is_string($token) || '' === $token || !\is_int($lifetime)) {
            throw IdentityProviderUnavailableException::unreachable();
        }

        $this->token = $token;
        $this->reusableUntil = $now + $lifetime - self::SAFETY_MARGIN;

        return $token;
    }

    /**
     * @return array<mixed>
     *
     * @throws IdentityProviderUnavailableException
     */
    private function requestPayload(): array
    {
        try {
            $response = $this->client->request('POST', self::TOKEN_PATH, ['body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]]);

            if (200 !== $response->getStatusCode()) {
                throw IdentityProviderUnavailableException::unreachable();
            }

            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw IdentityProviderUnavailableException::unreachable($exception);
        }
    }
}
