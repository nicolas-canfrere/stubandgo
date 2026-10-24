<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseSchemaCommentsTest extends KernelTestCase
{
    public function testEveryTableAndColumnHasAComment(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $tables = $connection->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relname <> 'doctrine_migration_versions'
              AND COALESCE(obj_description(c.oid, 'pg_class'), '') = ''
            SQL);
        $columns = $connection->fetchFirstColumn(<<<'SQL'
            SELECT c.relname || '.' || a.attname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relname <> 'doctrine_migration_versions'
              AND COALESCE(col_description(c.oid, a.attnum), '') = ''
            SQL);

        self::assertSame([], [...array_map(static fn(mixed $t): string => 'table ' . json_encode($t, \JSON_THROW_ON_ERROR), $tables), ...array_map(static fn(mixed $c): string => 'column ' . json_encode($c, \JSON_THROW_ON_ERROR), $columns)], 'Tables and columns without a PostgreSQL comment.');
    }
}
