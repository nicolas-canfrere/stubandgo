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

    public function testAnInvitedMemberIsNotAskedForNamesTheInvitationDoesNotCarry(): void
    {
        $contents = file_get_contents(\dirname(__DIR__, 4) . '/docker/keycloak/stubandgo-realm.json');
        self::assertIsString($contents);
        /** @var array{components: array<string, list<array{config: array{'kc.user.profile.config': list<string>}}>>} $realm */
        $realm = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        /** @var array{attributes: list<array{name: string, required?: mixed}>} $profile */
        $profile = json_decode(
            $realm['components']['org.keycloak.userprofile.UserProfileProvider'][0]['config']['kc.user.profile.config'][0],
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        $required = [];
        foreach ($profile['attributes'] as $attribute) {
            $required[$attribute['name']] = isset($attribute['required']);
        }

        self::assertSame(['username' => false, 'email' => true, 'firstName' => false, 'lastName' => false], $required);
    }
}
