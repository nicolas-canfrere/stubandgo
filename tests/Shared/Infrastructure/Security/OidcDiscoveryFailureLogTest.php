<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Tests\Shared\Infrastructure\Observability\LogRecordsTrait;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\RS256;
use Monolog\Level;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\Oidc\OidcTokenHandler;

/**
 * The test environment trusts a static key set, so the discovery path is exercised here on a handler wired to
 * the application logger, with Keycloak replaced by an HTTP client that fails.
 */
final class OidcDiscoveryFailureLogTest extends KernelTestCase
{
    use LogRecordsTrait;

    public function testAKeycloakThatCannotBeReachedIsStillLoggedAsAnError(): void
    {
        $logger = self::getContainer()->get('logger');
        $handler = new OidcTokenHandler(new AlgorithmManager([new RS256()]), null, 'stubandgo-api', ['https://sso.example/realms/stubandgo'], logger: $logger);
        $handler->enableDiscovery(new ArrayAdapter(), new MockHttpClient(new MockResponse('', ['http_code' => 503]), 'https://sso.example/realms/stubandgo/'), 'oidc_configuration');

        try {
            $handler->getUserBadgeFrom('not-a-jwt');
            self::fail('The token was accepted without keys.');
        } catch (BadCredentialsException) {
        }

        $records = self::logRecords('An error occurred while requesting OIDC certs.');
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
    }
}
