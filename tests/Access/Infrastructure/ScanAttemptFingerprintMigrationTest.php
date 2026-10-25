<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Replays the migration on the test database inside a transaction (PostgreSQL DDL is transactional): the schema is
 * put back in its previous shape, a legacy row is inserted, then the real migration SQL runs.
 */
final class ScanAttemptFingerprintMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testExistingRowsAreConvertedInPlaceToTheSha256OfTheirStoredValue(): void
    {
        $this->restoreLegacyShape();
        $legacy = [
            ['01J00000000000000000000001', 'abc'],
            ['01J00000000000000000000002', "t\u{FFFD}k\u{e9}n"],
            ['01J00000000000000000000003', ''],
            ['01J00000000000000000000004', str_repeat('b', 128)],
        ];
        foreach ($legacy as [$id, $token]) {
            $this->connection->executeStatement(
                "INSERT INTO scan_attempt (id, event_id, token, outcome, scanned_at) VALUES (?, '01J000000000000000000000EV', ?, 'unknown', now())",
                [$id, $token],
            );
        }

        $this->runMigration('up');

        $rows = $this->connection->fetchAllKeyValue("SELECT id, token_fingerprint FROM scan_attempt WHERE event_id = '01J000000000000000000000EV' ORDER BY id");
        self::assertSame(
            [
                '01J00000000000000000000001' => hash('sha256', 'abc'),
                '01J00000000000000000000002' => hash('sha256', "t\u{FFFD}k\u{e9}n"),
                '01J00000000000000000000003' => hash('sha256', ''),
                '01J00000000000000000000004' => hash('sha256', str_repeat('b', 128)),
            ],
            $rows,
        );
        self::assertSame('character', $this->columnType('token_fingerprint')['data_type']);
        self::assertSame(64, $this->columnType('token_fingerprint')['character_maximum_length']);
        self::assertSame('NO', $this->columnType('token_fingerprint')['is_nullable']);
        $comment = $this->connection->fetchOne("SELECT col_description('scan_attempt'::regclass, (SELECT attnum FROM pg_attribute WHERE attrelid = 'scan_attempt'::regclass AND attname = 'token_fingerprint'))");
        self::assertIsString($comment);
        self::assertStringContainsString('SHA-256', $comment);
    }

    public function testDownRestoresTheColumnNameAndTypeKeepingTheFingerprints(): void
    {
        $this->restoreLegacyShape();
        $this->connection->executeStatement(
            "INSERT INTO scan_attempt (id, event_id, token, outcome, scanned_at) VALUES ('01J00000000000000000000001', '01J000000000000000000000EV', 'abc', 'unknown', now())",
        );
        $this->runMigration('up');

        $this->runMigration('down');

        self::assertSame('text', $this->columnType('token')['data_type']);
        self::assertSame(hash('sha256', 'abc'), $this->connection->fetchOne('SELECT token FROM scan_attempt'));
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'scan_attempt' AND column_name = 'token_fingerprint'"));
    }

    public function testANonFingerprintValueIsRejectedByTheDatabase(): void
    {
        $this->expectException(DriverException::class);

        $this->connection->executeStatement(
            "INSERT INTO scan_attempt (id, event_id, token_fingerprint, outcome, scanned_at) VALUES ('01J00000000000000000000009', '01J000000000000000000000EV', ?, 'unknown', now())",
            [str_repeat('G', 64)],
        );
    }

    private function restoreLegacyShape(): void
    {
        $this->connection->executeStatement('ALTER TABLE scan_attempt DROP CONSTRAINT scan_attempt_token_fingerprint_check');
        $this->connection->executeStatement('ALTER TABLE scan_attempt RENAME COLUMN token_fingerprint TO token');
        $this->connection->executeStatement('ALTER TABLE scan_attempt ALTER COLUMN token TYPE TEXT');
    }

    /**
     * @return array{data_type: string, character_maximum_length: int|null, is_nullable: string}
     */
    private function columnType(string $column): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'scan_attempt' AND column_name = ?",
            [$column],
        );
        \assert(\is_array($row));
        \assert(\is_string($row['data_type']) && \is_string($row['is_nullable']));

        return ['data_type' => $row['data_type'], 'character_maximum_length' => is_numeric($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null, 'is_nullable' => $row['is_nullable']];
    }

    /**
     * @param 'up'|'down' $direction
     */
    private function runMigration(string $direction): void
    {
        $file = \dirname(__DIR__, 3) . '/migrations/Version20261025120000FingerprintScanAttemptToken.php';
        require_once $file;
        $class = 'DoctrineMigrations\\' . basename($file, '.php');
        \assert(class_exists($class));
        $migration = new \ReflectionClass($class)->newInstance($this->connection, new NullLogger());
        \assert($migration instanceof AbstractMigration);
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
