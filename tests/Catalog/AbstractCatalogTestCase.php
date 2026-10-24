<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Core\Application\Command\CreateEvent;
use App\Catalog\Core\Application\Command\CreateOrganization;
use App\Catalog\Core\Application\Command\PublishEvent;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

abstract class AbstractCatalogTestCase extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected const string NOW = '2026-10-24T12:00:00+00:00';

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    protected function command(object $command): void
    {
        self::getContainer()->get(CommandBusInterface::class)->dispatch($command);
    }

    protected function ask(object $query): mixed
    {
        return self::getContainer()->get(QueryBusInterface::class)->ask($query);
    }

    /**
     * @return array{id: string, slug: string}
     */
    protected function createOrganization(int $commissionBasisPoints = 1000): array
    {
        $id = OrganizationId::generate()->toString();
        $slug = 'org-' . strtolower($id);
        $this->command(new CreateOrganization($id, 'Rock Asso', $slug, $commissionBasisPoints));

        return ['id' => $id, 'slug' => $slug];
    }

    /**
     * @param list<array{id: string, label: string, priceCents: int}>|null $priceTiers
     */
    protected function createEvent(string $organizationId, string $startsAt, bool $publish = false, ?array $priceTiers = null, string $title = 'Concert'): string
    {
        $id = EventId::generate()->toString();
        $this->command(new CreateEvent(
            $id,
            $organizationId,
            $title,
            'Un super concert',
            'La Cigale',
            $startsAt,
            100,
            $priceTiers ?? [['id' => PriceTierId::generate()->toString(), 'label' => 'Plein', 'priceCents' => 2500]],
        ));

        if ($publish) {
            $this->command(new PublishEvent($id));
        }

        return $id;
    }
}
