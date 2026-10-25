<?php

declare(strict_types=1);

namespace App\Tests\Support\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\Clock\Clock;

/**
 * Signs RS256 access tokens the way Keycloak does, with the test-only keys of this directory.
 *
 * The test environment trusts the public keys of jwks.json (security.yaml, when@test): a token signed with
 * signing-private-key.pem is accepted, one signed with the other keys is not. These keys are test material only.
 */
final class TestTokenIssuer
{
    public const string ORGANIZATIONS_ISSUER = 'http://localhost:8180/realms/stubandgo';
    public const string PLATFORM_ISSUER = 'http://localhost:8180/realms/stubandgo-staff';
    public const string ROCK_ASSO_KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01';
    public const string SUBJECT = '5d3c2b1a-0f9e-4d8c-b7a6-958473625140';

    private const string SIGNING_KEY_ID = 'test-signing';

    /**
     * A token of the Rock Asso controller; each override replaces a claim, or removes it when null.
     *
     * @param array<string, mixed> $overrides
     */
    public static function issue(array $overrides = []): string
    {
        return self::sign(self::claims($overrides), 'signing-private-key.pem', self::SIGNING_KEY_ID);
    }

    /**
     * The same token, signed with a key the API does not know, under the key id of the trusted one.
     *
     * @param array<string, mixed> $overrides
     */
    public static function issueWithForeignKey(array $overrides = []): string
    {
        return self::sign(self::claims($overrides), 'foreign-private-key.pem', self::SIGNING_KEY_ID);
    }

    /**
     * The same token, signed with the private half of the encryption key the API's key set lists ("use": "enc").
     *
     * @param array<string, mixed> $overrides
     */
    public static function issueWithEncryptionKey(array $overrides = []): string
    {
        return self::sign(self::claims($overrides), 'encryption-private-key.pem', 'test-encryption');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function claims(array $overrides): array
    {
        $now = Clock::get()->now()->getTimestamp();
        $claims = [
            'exp' => $now + 300,
            'iat' => $now,
            'jti' => 'onrtac:' . bin2hex(random_bytes(8)),
            'iss' => self::ORGANIZATIONS_ISSUER,
            'aud' => 'stubandgo-api',
            'sub' => self::SUBJECT,
            'typ' => 'Bearer',
            'azp' => 'stubandgo-app',
            'realm_access' => ['roles' => ['organization-controller']],
            'organization' => ['demo-rock-asso' => ['id' => self::ROCK_ASSO_KEYCLOAK_ID]],
            'preferred_username' => 'controleur.rock',
        ];

        return array_filter([...$claims, ...$overrides], static fn(mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function sign(array $claims, string $keyFile, string $keyId): string
    {
        $jws = new JWSBuilder(new AlgorithmManager([new RS256()]))
            ->create()
            ->withPayload(json_encode($claims, \JSON_THROW_ON_ERROR))
            ->addSignature(self::key($keyFile), ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId])
            ->build();

        return new CompactSerializer()->serialize($jws, 0);
    }

    private static function key(string $keyFile): JWK
    {
        return JWKFactory::createFromKeyFile(__DIR__ . '/' . $keyFile);
    }
}
