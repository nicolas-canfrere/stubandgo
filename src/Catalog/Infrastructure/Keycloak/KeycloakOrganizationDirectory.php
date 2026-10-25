<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Keycloak;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Core\Domain\Exception\MemberAlreadyExistsException;
use App\Catalog\Core\Domain\OrganizationDirectoryInterface;
use App\Catalog\Core\Domain\ValueObject\KeycloakOrganizationId;
use App\Catalog\Core\Domain\ValueObject\MemberRole;
use App\Catalog\Core\Domain\ValueObject\OrganizationName;
use App\Catalog\Core\Domain\ValueObject\Slug;
use App\Shared\Exception\InvalidValueException;
use App\Shared\ValueObject\EmailAddress;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Drives the Keycloak admin REST API of the stubandgo realm with the API's service account.
 */
final readonly class KeycloakOrganizationDirectory implements OrganizationDirectoryInterface
{
    private const string REALM_PATH = '/admin/realms/stubandgo';
    private const string APP_CLIENT_ID = 'stubandgo-app';
    private const string APP_REDIRECT_URI = 'http://localhost:5173/espace';
    /** Validity of the invitation link: 72 hours. */
    private const int INVITATION_LIFESPAN = 259200;

    public function __construct(
        #[Autowire(service: 'keycloak.admin.client')]
        private HttpClientInterface $client,
        private ServiceAccountTokenProvider $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function ensureOrganization(Slug $slug, OrganizationName $name): KeycloakOrganizationId
    {
        $existing = $this->findOrganization($slug);

        if (null !== $existing) {
            return $existing;
        }

        $response = $this->send('POST', '/organizations', ['json' => ['name' => $name->toString(), 'alias' => $slug->toString()]]);

        if (201 === $response->getStatusCode()) {
            return $this->createdId($response);
        }

        // Lost a race on the alias; any other conflict (the name is taken under another alias) is not ours to adopt.
        $raced = 409 === $response->getStatusCode() ? $this->findOrganization($slug) : null;

        return $raced ?? throw IdentityProviderUnavailableException::unreachable();
    }

    public function inviteMember(KeycloakOrganizationId $organization, EmailAddress $email, MemberRole $role): void
    {
        $response = $this->send('POST', '/users', ['json' => ['username' => $email->toString(), 'email' => $email->toString(), 'enabled' => true]]);

        if (409 === $response->getStatusCode()) {
            throw MemberAlreadyExistsException::withEmail($email);
        }

        if (201 !== $response->getStatusCode()) {
            throw IdentityProviderUnavailableException::unreachable();
        }

        $userId = $this->createdIdentifier($response);

        try {
            $this->addToOrganization($organization, $userId);
            $this->grantRole($userId, $role);
            $this->sendInvitationEmail($userId);
        } catch (IdentityProviderUnavailableException $exception) {
            $this->deleteUser($userId);

            throw $exception;
        }
    }

    private function findOrganization(Slug $slug): ?KeycloakOrganizationId
    {
        $response = $this->send('GET', '/organizations', ['query' => ['q' => 'alias:' . $slug->toString()]]);

        foreach ($this->body($response) as $organization) {
            if (\is_array($organization) && ($organization['alias'] ?? null) === $slug->toString() && \is_string($organization['id'] ?? null)) {
                return $this->organizationId($organization['id']);
            }
        }

        return null;
    }

    private function addToOrganization(KeycloakOrganizationId $organization, string $userId): void
    {
        // The member is identified by a bare JSON string, not an object.
        $response = $this->send('POST', \sprintf('/organizations/%s/members', $organization->toString()), ['json' => $userId]);

        if (201 !== $response->getStatusCode()) {
            throw IdentityProviderUnavailableException::unreachable();
        }
    }

    private function grantRole(string $userId, MemberRole $role): void
    {
        $roleName = $role->keycloakRealmRole();
        $lookup = $this->send('GET', \sprintf('/roles/%s', rawurlencode($roleName)));

        $roleId = $this->body($lookup)['id'] ?? null;

        if (!\is_string($roleId)) {
            throw IdentityProviderUnavailableException::unreachable();
        }

        // Keycloak refuses a role mapping that does not carry the role identifier.
        $response = $this->send('POST', \sprintf('/users/%s/role-mappings/realm', $userId), ['json' => [['id' => $roleId, 'name' => $roleName]]]);

        if (204 !== $response->getStatusCode()) {
            throw IdentityProviderUnavailableException::unreachable();
        }
    }

    private function sendInvitationEmail(string $userId): void
    {
        $response = $this->send('PUT', \sprintf('/users/%s/execute-actions-email', $userId), [
            'query' => ['client_id' => self::APP_CLIENT_ID, 'redirect_uri' => self::APP_REDIRECT_URI, 'lifespan' => self::INVITATION_LIFESPAN],
            'json' => ['UPDATE_PASSWORD'],
        ]);

        if (204 !== $response->getStatusCode()) {
            throw IdentityProviderUnavailableException::unreachable();
        }
    }

    /**
     * Removes the half-provisioned user so the invitation can be retried; a failure here is only logged.
     */
    private function deleteUser(string $userId): void
    {
        try {
            $deleted = 204 === $this->send('DELETE', \sprintf('/users/%s', $userId))->getStatusCode();
        } catch (IdentityProviderUnavailableException) {
            $deleted = false;
        }

        if (!$deleted) {
            $this->logger->error('Keycloak user left behind after a failed invitation.', ['keycloakUserId' => $userId]);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws IdentityProviderUnavailableException
     */
    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['auth_bearer'] = $this->tokens->token();

        try {
            $response = $this->client->request($method, self::REALM_PATH . $path, $options);
            // Responses are lazy: transport failures surface on the first read.
            $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw IdentityProviderUnavailableException::unreachable($exception);
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private function body(ResponseInterface $response): array
    {
        try {
            return $response->toArray();
        } catch (ExceptionInterface $exception) {
            throw IdentityProviderUnavailableException::unreachable($exception);
        }
    }

    /**
     * Identifier carried by the last segment of the Location header of a creation.
     */
    private function createdIdentifier(ResponseInterface $response): string
    {
        $segments = explode('/', $response->getHeaders()['location'][0] ?? '');
        $identifier = end($segments);

        if ('' === $identifier) {
            throw IdentityProviderUnavailableException::unreachable();
        }

        return $identifier;
    }

    private function createdId(ResponseInterface $response): KeycloakOrganizationId
    {
        return $this->organizationId($this->createdIdentifier($response));
    }

    private function organizationId(string $value): KeycloakOrganizationId
    {
        try {
            return KeycloakOrganizationId::fromString($value);
        } catch (InvalidValueException $exception) {
            throw IdentityProviderUnavailableException::unreachable($exception);
        }
    }
}
