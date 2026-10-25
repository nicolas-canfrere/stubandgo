<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalog;

use App\Catalog\Core\Application\Query\GetMemberOrganization;
use App\Catalog\Core\Application\Query\MemberOrganizationView;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Bus\QueryInterface;

/**
 * Query bus answering only GetMemberOrganization, from a fixed Keycloak organization id to organization map.
 */
final readonly class StubMemberOrganizationQueryBus implements QueryBusInterface
{
    /**
     * @param array<string, MemberOrganizationView> $organizations by Keycloak organization id
     */
    public function __construct(private array $organizations)
    {
    }

    /**
     * @template TResult
     *
     * @param QueryInterface<TResult> $query
     *
     * @return TResult
     */
    public function ask(QueryInterface $query): mixed
    {
        \assert($query instanceof GetMemberOrganization);

        /** @var TResult $organization */
        $organization = $this->organizations[$query->keycloakOrganizationId] ?? null;

        return $organization;
    }
}
