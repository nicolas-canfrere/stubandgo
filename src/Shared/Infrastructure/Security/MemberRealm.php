<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * The Keycloak realm a member signed in to, told apart by the issuer of their access token.
 */
enum MemberRealm: string
{
    /** Members of the organizations that sell tickets (realm "stubandgo"). */
    case Organizations = 'organizations';

    /** Staff of the platform itself (realm "stubandgo-staff"). */
    case Platform = 'platform';
}
