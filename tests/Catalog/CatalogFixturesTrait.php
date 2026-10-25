<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Core\Application\Command\CreateEvent;
use App\Catalog\Core\Application\Command\CreateOrganization;
use App\Catalog\Core\Application\Command\PublishEvent;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\CommandInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Bus\QueryInterface;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;

trait CatalogFixturesTrait
{
    protected const string NOW = '2026-10-24T12:00:00+00:00';

    protected function command(CommandInterface $command): void
    {
        self::getContainer()->get(CommandBusInterface::class)->dispatch($command);
    }

    /**
     * @template TResult
     *
     * @param QueryInterface<TResult> $query
     *
     * @return TResult
     */
    protected function ask(QueryInterface $query): mixed
    {
        return self::getContainer()->get(QueryBusInterface::class)->ask($query);
    }

    /**
     * Each organization gets a distinct name, since organization names are unique.
     *
     * @return array{id: string, slug: string, name: string}
     */
    protected function createOrganization(int $commissionBasisPoints = 1000, ?string $keycloakOrganizationId = null): array
    {
        $id = OrganizationId::generate()->toString();
        $slug = 'org-' . strtolower($id);
        $name = 'Rock Asso ' . $id;
        $this->command(new CreateOrganization($id, $name, $slug, $commissionBasisPoints, $keycloakOrganizationId));

        return ['id' => $id, 'slug' => $slug, 'name' => $name];
    }

    /**
     * @param list<array{id: string, label: string, priceCents: int}>|null $priceTiers
     */
    protected function createEvent(string $organizationId, string $startsAt, bool $publish = false, ?array $priceTiers = null, string $title = 'Concert', int $capacity = 100): string
    {
        $id = EventId::generate()->toString();
        $this->command(new CreateEvent(
            $id,
            $organizationId,
            $title,
            'Un super concert',
            'La Cigale',
            $startsAt,
            $capacity,
            $priceTiers ?? [['id' => PriceTierId::generate()->toString(), 'label' => 'Plein', 'priceCents' => 2500]],
        ));

        if ($publish) {
            $this->command(new PublishEvent($id));
        }

        return $id;
    }
}
