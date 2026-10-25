<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

enum OrganizationStatus: string
{
    case PendingKeycloak = 'pending_keycloak';
    case Active = 'active';
}
