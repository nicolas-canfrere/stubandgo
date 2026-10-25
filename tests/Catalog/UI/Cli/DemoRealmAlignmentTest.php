<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Cli;

use App\Catalog\UI\Cli\LoadDemoDataCommand;
use PHPUnit\Framework\TestCase;

final class DemoRealmAlignmentTest extends TestCase
{
    public function testDemoOrganizationsMatchTheRealmExport(): void
    {
        $contents = file_get_contents(\dirname(__DIR__, 4) . '/docker/keycloak/stubandgo-realm.json');
        self::assertIsString($contents);
        /** @var array{organizations: list<array{alias: string, id: string}>} $realm */
        $realm = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        $exported = [];
        foreach ($realm['organizations'] as $organization) {
            $exported[$organization['alias']] = $organization['id'];
        }

        self::assertCount(\count($realm['organizations']), $exported);
        self::assertEqualsCanonicalizing(LoadDemoDataCommand::KEYCLOAK_ORGANIZATION_IDS, $exported);
        self::assertSame(LoadDemoDataCommand::KEYCLOAK_ORGANIZATION_IDS, array_replace(LoadDemoDataCommand::KEYCLOAK_ORGANIZATION_IDS, $exported));
    }
}
