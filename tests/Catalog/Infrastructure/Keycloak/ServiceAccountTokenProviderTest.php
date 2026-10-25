<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure\Keycloak;

use App\Catalog\Core\Domain\Exception\IdentityProviderUnavailableException;
use App\Catalog\Infrastructure\Keycloak\ServiceAccountTokenProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ServiceAccountTokenProviderTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-25 10:00:00');
    }

    public function testItRequestsATokenWithTheClientCredentials(): void
    {
        $http = new ScriptedHttp(ScriptedHttp::json(200, ['access_token' => 'tok-1', 'expires_in' => 300]));

        self::assertSame('tok-1', $this->provider($http)->token());

        $request = $http->requests()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://keycloak:8080/realms/stubandgo/protocol/openid-connect/token', $request['url']);
        parse_str($request['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'stubandgo-admin-api', 'client_secret' => 'the-secret'], $form);
    }

    public function testTheTokenIsReusedUntilShortlyBeforeItExpires(): void
    {
        $http = new ScriptedHttp(
            ScriptedHttp::json(200, ['access_token' => 'tok-1', 'expires_in' => 300]),
            ScriptedHttp::json(200, ['access_token' => 'tok-2', 'expires_in' => 300]),
        );
        $provider = $this->provider($http);

        self::assertSame('tok-1', $provider->token());
        $this->clock->sleep(269);
        self::assertSame('tok-1', $provider->token());
        self::assertSame(1, $http->requestCount());

        $this->clock->sleep(1);
        self::assertSame('tok-2', $provider->token());
        self::assertSame(2, $http->requestCount());
    }

    /**
     * @param \Closure(): MockResponse $response
     */
    #[DataProvider('failures')]
    public function testAnythingButATokenIsReportedAsUnavailable(\Closure $response): void
    {
        $this->expectException(IdentityProviderUnavailableException::class);

        $this->provider(new ScriptedHttp($response()))->token();
    }

    /**
     * @return iterable<string, array{\Closure(): MockResponse}>
     */
    public static function failures(): iterable
    {
        yield 'unauthorized' => [static fn() => ScriptedHttp::json(401, ['error' => 'unauthorized_client'])];
        yield 'unauthorized with a token' => [static fn() => ScriptedHttp::json(401, ['access_token' => 'tok', 'expires_in' => 300])];
        yield 'server error' => [static fn() => ScriptedHttp::status(503)];
        yield 'no lifetime' => [static fn() => ScriptedHttp::json(200, ['access_token' => 'tok'])];
        yield 'lifetime of the wrong type' => [static fn() => ScriptedHttp::json(200, ['access_token' => 'tok', 'expires_in' => 'soon'])];
        yield 'created instead of ok' => [static fn() => ScriptedHttp::json(201, ['access_token' => 'tok', 'expires_in' => 300])];
        yield 'no token' => [static fn() => ScriptedHttp::json(200, ['expires_in' => 300])];
        yield 'empty token' => [static fn() => ScriptedHttp::json(200, ['access_token' => '', 'expires_in' => 300])];
        yield 'token of the wrong type' => [static fn() => ScriptedHttp::json(200, ['access_token' => 42, 'expires_in' => 300])];
        yield 'not json' => [static fn() => new MockResponse('<html>', ['http_code' => 200])];
        yield 'transport failure' => [static fn() => ScriptedHttp::transportFailure()];
    }

    public function testAnExpiredTokenIsNotReusedWhenItsRenewalFails(): void
    {
        $http = new ScriptedHttp(
            ScriptedHttp::json(200, ['access_token' => 'tok-1', 'expires_in' => 300]),
            ScriptedHttp::status(503),
        );
        $provider = $this->provider($http);
        $provider->token();
        $this->clock->sleep(270);

        try {
            $provider->token();
            self::fail('The expired token was reused.');
        } catch (IdentityProviderUnavailableException $exception) {
            self::assertStringNotContainsString('the-secret', $exception->getMessage());
        }
    }

    private function provider(ScriptedHttp $http): ServiceAccountTokenProvider
    {
        return new ServiceAccountTokenProvider($http->client(), $this->clock, 'stubandgo-admin-api', 'the-secret');
    }
}
