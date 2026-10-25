<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Observability\Health;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;

final class HealthEndpointTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-10-25T12:00:00+00:00';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $this->client = self::createClient();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM outbox_message');
        $this->beatSecondsAgo(1);
    }

    public function testHealthIsOkWhenAllChecksPass(): void
    {
        $body = $this->health();

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('ok', $body['status']);
        self::assertEqualsCanonicalizing(['database', 'rabbitmq', 'outbox', 'worker'], array_keys($body['checks']));
        foreach ($body['checks'] as $name => $check) {
            self::assertTrue($check['healthy'], $name);
            self::assertNotSame('', $check['detail'], $name);
        }
    }

    public function testHealthReturns503WhenWorkerSilentFor61Seconds(): void
    {
        $this->beatSecondsAgo(61);

        $body = $this->health();

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('degraded', $body['status']);
        self::assertFalse($body['checks']['worker']['healthy']);
        self::assertTrue($body['checks']['database']['healthy']);
        self::assertTrue($body['checks']['outbox']['healthy']);
        self::assertTrue($body['checks']['rabbitmq']['healthy']);
    }

    public function testHealthIsOkWhenWorkerSilentFor60Seconds(): void
    {
        $this->beatSecondsAgo(60);

        $this->health();

        self::assertResponseStatusCodeSame(200);
    }

    public function testHealthReturns503WhenWorkerWasNeverSeen(): void
    {
        $this->connection->executeStatement('DELETE FROM worker_heartbeat');

        $body = $this->health();

        self::assertResponseStatusCodeSame(503);
        self::assertFalse($body['checks']['worker']['healthy']);
        self::assertStringContainsString('never seen', $body['checks']['worker']['detail']);
    }

    public function testHealthReturns503WhenOldestOutboxMessageIs121SecondsOld(): void
    {
        $this->insertOutboxRow('-121 seconds');

        $body = $this->health();

        self::assertResponseStatusCodeSame(503);
        self::assertSame('degraded', $body['status']);
        self::assertFalse($body['checks']['outbox']['healthy']);
        self::assertTrue($body['checks']['worker']['healthy']);
    }

    public function testHealthIsOkWhenOldestOutboxMessageIs120SecondsOld(): void
    {
        $this->insertOutboxRow('-120 seconds');

        $this->health();

        self::assertResponseStatusCodeSame(200);
    }

    public function testHealthReturns503WithEveryCheckWhenTheDatabaseFails(): void
    {
        try {
            $this->connection->executeStatement('SELECT 1 / 0');
        } catch (\Throwable) {
            // The failed statement aborts the test transaction: every later query fails, as with a database that is down.
        }

        $body = $this->health();

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('degraded', $body['status']);
        self::assertEqualsCanonicalizing(['database', 'rabbitmq', 'outbox', 'worker'], array_keys($body['checks']));
        foreach (['database', 'outbox', 'worker'] as $name) {
            self::assertFalse($body['checks'][$name]['healthy'], $name);
            self::assertStringNotContainsString('SQLSTATE', $body['checks'][$name]['detail']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsThatAreNotPending(): iterable
    {
        yield 'published' => ['published_at'];
        yield 'quarantined' => ['failed_at'];
    }

    #[DataProvider('rowsThatAreNotPending')]
    public function testOldOutboxRowsThatAreNotPendingDoNotAffectHealth(string $column): void
    {
        $this->insertOutboxRow('-1 hour', $column);

        $this->health();

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array{status: string, checks: array<string, array{healthy: bool, detail: string}>}
     */
    private function health(): array
    {
        $this->client->request('GET', '/health');
        /** @var array{status: string, checks: array<string, array{healthy: bool, detail: string}>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    private function beatSecondsAgo(int $seconds): void
    {
        $this->connection->executeStatement(
            'INSERT INTO worker_heartbeat (id, last_seen_at) VALUES (1, ?::timestamptz - make_interval(secs => ?)) ON CONFLICT (id) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at',
            [self::NOW, $seconds],
        );
    }

    private function insertOutboxRow(string $age, ?string $markedColumn = null): void
    {
        $at = new \DateTimeImmutable(self::NOW)->modify($age)->format('Y-m-d H:i:sP');
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO outbox_message (id, message_class, payload, created_at, published_at, failed_at, failure_reason) VALUES (?, ?, ?, ?, %s, %s, %s)',
                'published_at' === $markedColumn ? '?::timestamptz' : 'NULL',
                'failed_at' === $markedColumn ? '?::timestamptz' : 'NULL',
                'failed_at' === $markedColumn ? "'x'" : 'NULL',
            ),
            [Uuid::v7()->toRfc4122(), 'App\\Gone', '{}', $at, ...(null === $markedColumn ? [] : [$at])],
        );
    }
}
