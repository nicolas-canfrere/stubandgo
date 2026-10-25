<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000FingerprintScanAttemptToken extends AbstractMigration
{
    private const string FINGERPRINT_COMMENT = 'SHA-256 fingerprint (64 lowercase hexadecimal characters) of the bytes the scanner presented; the token itself is never stored, as a wrong_event scan leaves it a valid bearer credential. Rows written before this column existed were fingerprinted from their stored value, which was already truncated and scrubbed.';
    private const string LEGACY_COMMENT = 'What the scanner presented, truncated to 128 characters, invalid UTF-8 scrubbed and NUL bytes replaced by U+FFFD; may be a valid bearer credential, never logged.';

    public function getDescription(): string
    {
        return 'Store a SHA-256 fingerprint instead of the presented token in scan_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_attempt RENAME COLUMN token TO token_fingerprint');
        // Existing rows are converted in place: the original token is gone, only its stored (possibly truncated) form is hashed.
        $this->addSql("ALTER TABLE scan_attempt ALTER COLUMN token_fingerprint TYPE CHAR(64) USING encode(sha256(convert_to(token_fingerprint, 'UTF8')), 'hex')");
        $this->addSql(\sprintf('COMMENT ON COLUMN scan_attempt.token_fingerprint IS %s', $this->connection->quote(self::FINGERPRINT_COMMENT)));
    }

    public function down(Schema $schema): void
    {
        // Irreversible data-wise: the tokens are not recoverable, so the column keeps holding fingerprints under its old name and type.
        $this->addSql('ALTER TABLE scan_attempt ALTER COLUMN token_fingerprint TYPE TEXT');
        $this->addSql('ALTER TABLE scan_attempt RENAME COLUMN token_fingerprint TO token');
        $this->addSql(\sprintf('COMMENT ON COLUMN scan_attempt.token IS %s', $this->connection->quote(self::LEGACY_COMMENT)));
    }
}
