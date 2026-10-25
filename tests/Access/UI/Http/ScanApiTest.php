<?php

declare(strict_types=1);

namespace App\Tests\Access\UI\Http;

use App\Shared\Identity\EventId;
use App\Tests\Access\AdmissionRightFixturesTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ScanApiTest extends WebTestCase
{
    use AdmissionRightFixturesTrait;
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $clock = self::mockTime(new \DateTimeImmutable('2026-11-20T20:00:00+01:00'));
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
        $this->client = self::createClient();
    }

    public function testFirstScanIsValid(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['outcome' => 'valid', 'firstUsedAt' => null], $body);
    }

    public function testSecondScanIsAlreadyUsedWithTheFirstDateInUtc(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();
        $this->scan($eventId, ['token' => $token]);
        $this->clock->sleep(120);

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'already_used', 'firstUsedAt' => '2026-11-20T19:00:00Z'], $body);
    }

    public function testScanForAnotherEventIsWrongEvent(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();

        $body = $this->scan(EventId::generate()->toString(), ['token' => $token]);

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
        $eventId = EventId::generate()->toString();

        $body = $this->scan($eventId, ['token' => $token]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'unknown', 'firstUsedAt' => null], $body);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testNulCharacterIsFingerprintedLikeAnyOtherByte(): void
    {
        $eventId = EventId::generate()->toString();

        $this->client->request('POST', '/api/events/' . $eventId . '/scans', server: ['CONTENT_TYPE' => 'application/json'], content: '{"token": "a\u0000b"}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(hash('sha256', "a\0b"), $this->connection()->fetchOne('SELECT token_fingerprint FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testWrongEventScanLogsTheFingerprintNeverTheStillValidToken(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();
        $eventId = EventId::generate()->toString();

        $this->scan($eventId, ['token' => $token]);

        $this->assertOnlyTheFingerprintIsLogged($eventId, $token, 'wrong_event');
    }

    public function testValidScanLogsTheFingerprintNeverTheToken(): void
    {
        ['eventId' => $eventId, 'token' => $token] = $this->issueAdmissionRight();

        $this->scan($eventId, ['token' => $token]);

        $this->assertOnlyTheFingerprintIsLogged($eventId, $token, 'valid');
    }

    public function testUnknownScanLogsTheFingerprintNeverTheToken(): void
    {
        $token = $this->unknownToken();
        $eventId = EventId::generate()->toString();

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
        $eventId = EventId::generate()->toString();

        $problem = $this->scan($eventId, $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/validation_failed', $problem['type'] ?? null);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertContains('token', array_column($problem['violations'], 'propertyPath'));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt WHERE event_id = ?', [$eventId]));
    }

    public function testMalformedEventIdReturns422WithoutRecordingTheScan(): void
    {
        ['token' => $token] = $this->issueAdmissionRight();
        $before = $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt');

        $problem = $this->scan('not-an-event', ['token' => $token]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('/problems/invalid_value', $problem['type'] ?? null);
        self::assertSame('/api/events/not-an-event/scans', $problem['instance'] ?? null);
        self::assertSame($before, $this->connection()->fetchOne('SELECT COUNT(*) FROM scan_attempt'));
    }

    public function testApiDocDescribesTheScanRoute(): void
    {
        $this->client->request('GET', '/api/doc.json');
        $doc = $this->decode();

        self::assertResponseIsSuccessful();
        $operation = $this->docNode($doc, 'paths', '/api/events/{eventId}/scans', 'post');
        self::assertIsArray($operation);
        $this->docNode($operation, 'responses', '200');
        $this->docNode($operation, 'responses', '422');
        $description = $this->docNode($operation, 'description');
        self::assertIsString($description);
        self::assertStringContainsString('not protected in L1', $description);
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
        $this->client->request('POST', '/api/events/' . $eventId . '/scans', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
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
