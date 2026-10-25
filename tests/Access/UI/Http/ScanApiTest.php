<?php

declare(strict_types=1);

namespace App\Tests\Access\UI\Http;

use App\Tests\Access\AdmissionRightFixturesTrait;
use App\Tests\Catalog\CatalogFixturesTrait;
use App\Tests\Shared\Infrastructure\Observability\LogRecordsTrait;
use App\Tests\Support\Security\TestTokenIssuer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ScanApiTest extends WebTestCase
{
    use AdmissionRightFixturesTrait;
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;
    use LogRecordsTrait;

    private const string THEATRE_KEYCLOAK_ID = '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02';
    private const string EVENT_STARTS_AT = '2026-12-01T20:00:00+01:00';

    private KernelBrowser $client;
    private MockClock $clock;

    /** A published event of Rock Asso, the organization of the default test token. */
    private string $rockEventId;

    /** @var array<string, string> published events by fixture name */
    private array $events;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable('2026-11-20T20:00:00+01:00'));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
        $this->client = self::createClient();

        $rockAsso = $this->createOrganization(keycloakOrganizationId: TestTokenIssuer::ROCK_ASSO_KEYCLOAK_ID)['id'];
        $this->rockEventId = $this->createEvent($rockAsso, self::EVENT_STARTS_AT, publish: true);
        $this->events = [
            'rock' => $this->rockEventId,
            'other rock' => $this->createEvent($rockAsso, self::EVENT_STARTS_AT, publish: true),
            'theatre' => $this->createEvent($this->createOrganization(keycloakOrganizationId: self::THEATRE_KEYCLOAK_ID)['id'], self::EVENT_STARTS_AT, publish: true),
            'unlinked' => $this->createEvent($this->createOrganization()['id'], self::EVENT_STARTS_AT, publish: true),
        ];
    }

    public function testFirstScanIsValid(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight($this->rockEventId);

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['outcome' => 'valid', 'firstUsedAt' => null], $body);
    }

    public function testSecondScanIsAlreadyUsedWithTheFirstDateInUtc(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight($this->rockEventId);
        $this->scan($eventId, ['token' => $token]);
        $this->clock->sleep(120);

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'already_used', 'firstUsedAt' => '2026-11-20T19:00:00Z'], $body);
    }

    public function testTicketPresentedToAnotherEventOfTheOrganizationIsWrongEvent(): void
    {
        ['token' => $token] = $this->issueAdmissionRight($this->rockEventId);

        $body = $this->scan($this->events['other rock'], ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'wrong_event', 'firstUsedAt' => null], $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'short' => ['abc'];
        yield 'empty' => [''];
        yield '500 characters' => [str_repeat('a', 500)];
        yield 'NUL character' => ["\0"];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenReturnsUnknownNot500(string $token): void
    {
        $eventId = $this->rockEventId;

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'unknown', 'firstUsedAt' => null], $body);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testNulCharacterIsFingerprintedLikeAnyOtherByte(): void
    {
        $eventId = $this->rockEventId;

        $this->client->request('POST', '/api/events/' . $eventId . '/scans', server: $this->headers(TestTokenIssuer::issue()), content: '{"token": "a\u0000b"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(hash('sha256', "a\0b"), $this->connection()->fetchOne('SELECT token_fingerprint FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testWrongEventScanLogsTheFingerprintNeverTheStillValidToken(): void
    {
        ['token' => $token] = $this->issueAdmissionRight($this->rockEventId);
        $eventId = $this->events['other rock'];

        $this->scan($eventId, ['token' => $token]);

        $this->assertOnlyTheFingerprintIsLogged($eventId, $token, 'wrong_event');
    }

    public function testValidScanLogsTheFingerprintNeverTheToken(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight($this->rockEventId);

        $this->scan($eventId, ['token' => $token]);

        $this->assertOnlyTheFingerprintIsLogged($eventId, $token, 'valid');
    }

    public function testUnknownScanLogsTheFingerprintNeverTheToken(): void
    {
        $token = $this->unknownToken();
        $eventId = $this->rockEventId;

        $this->scan($eventId, ['token' => $token]);

        $this->assertOnlyTheFingerprintIsLogged($eventId, $token, 'unknown');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing token' => [[]];
        yield 'null token' => [['token' => null]];
        yield 'integer token' => [['token' => 123]];
        yield 'array token' => [['token' => ['a']]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadReturnsValidationProblemWithoutRecordingTheScan(array $payload): void
    {
        $eventId = $this->rockEventId;

        $problem = $this->scan($eventId, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/validation_failed', $problem['type'] ?? null);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertContains('token', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testScanWithoutAccessTokenIsUnauthorizedAndNotRecorded(): void
    {
        ['token' => $token] = $this->issueAdmissionRight($this->rockEventId);

        $problem = $this->scanAs(null, $this->rockEventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame('/problems/unauthorized', $problem['type'] ?? null);
        $this->assertNothingRecorded($token);
        self::assertLoggedAsRejection();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function forbiddenScans(): iterable
    {
        yield 'controller of another organization' => ['rock', ['organization' => ['demo-theatre-ville' => ['id' => self::THEATRE_KEYCLOAK_ID]]]];
        yield 'administrator of the organization without the controller role' => ['rock', ['realm_access' => ['roles' => ['organization-admin']]]];
        yield 'platform administrator' => ['rock', ['iss' => TestTokenIssuer::PLATFORM_ISSUER, 'realm_access' => ['roles' => ['platform-admin']], 'organization' => null]];
        yield 'controller without organization' => ['rock', ['organization' => null]];
        yield 'event of another organization' => ['theatre', []];
        yield 'unknown event' => ['01JAE5Z8Q3M4N6P7R8S9T0V1W2', []];
        yield 'event identifier that is not a ULID' => ['not-a-ulid', []];
        yield 'event of an organization without Keycloak organization' => ['unlinked', []];
    }

    /**
     * @param string               $event          fixture name, or raw event identifier of the URL
     * @param array<string, mixed> $tokenOverrides claims of the access token that differ from a Rock Asso controller
     */
    #[DataProvider('forbiddenScans')]
    public function testScanNotAllowedToTheMemberIsForbiddenAndNotRecorded(string $event, array $tokenOverrides): void
    {
        ['token' => $token] = $this->issueAdmissionRight($this->rockEventId);
        $eventId = $this->events[$event] ?? $event;

        $problem = $this->scanAs(TestTokenIssuer::issue($tokenOverrides), $eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/forbidden', $problem['type'] ?? null);
        self::assertSame('Forbidden', $problem['title'] ?? null);
        self::assertSame('/api/events/' . $eventId . '/scans', $problem['instance'] ?? null);
        $this->assertNothingRecorded($token);
        self::assertLoggedAsRejection();
    }

    public function testApiDocDescribesTheScanRoute(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = $this->decode();

        self::assertResponseIsSuccessful();
        self::assertEquals(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $this->docNode($doc, 'components', 'securitySchemes', 'bearerAuth'));
        $operation = $this->docNode($doc, 'paths', '/api/events/{eventId}/scans', 'post');
        self::assertIsArray($operation);
        self::assertSame([['bearerAuth' => []]], $this->docNode($operation, 'security'));
        $this->docNode($operation, 'responses', '200');
        foreach (['401', '403', '422'] as $status) {
            self::assertSame('#/components/schemas/Problem', $this->docNode($operation, 'responses', $status, 'content', 'application/json', 'schema', '$ref'));
        }
        $description = $this->docNode($operation, 'description');
        self::assertIsString($description);
        self::assertStringNotContainsString('not protected', $description);
    }

    /**
     * No scan attempt at all (each test runs in its own rolled back transaction), and the admission right is unused.
     */
    private function assertNothingRecorded(string $token): void
    {
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt'));
        self::assertNull($this->connection()->fetchOne('SELECT used_at FROM admission_right WHERE token = ?', [$token]));
    }

    private function assertOnlyTheFingerprintIsLogged(string $eventId, string $token, string $outcome): void
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM scan_attempt WHERE event_id = ?', [$eventId]);

        self::assertCount(1, $rows);
        self::assertSame($outcome, $rows[0]['outcome']);
        self::assertSame(hash('sha256', $token), $rows[0]['token_fingerprint']);
        foreach ($rows[0] as $column => $value) {
            self::assertStringNotContainsString($token, \is_string($value) ? $value : '', \sprintf('Column "%s" holds the presented token.', $column));
        }
    }

    /**
     * @param array<mixed> $doc
     */
    private function docNode(array $doc, string ...$keys): mixed
    {
        $node = $doc;
        foreach ($keys as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function scan(string $eventId, array $payload): array
    {
        return $this->scanAs(TestTokenIssuer::issue(), $eventId, $payload);
    }

    /**
     * @param string|null          $accessToken null to send no access token
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function scanAs(?string $accessToken, string $eventId, array $payload): array
    {
        $this->client->request('POST', '/api/events/' . $eventId . '/scans', server: $this->headers($accessToken), content: json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $accessToken): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $accessToken) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
        }

        return $headers;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
