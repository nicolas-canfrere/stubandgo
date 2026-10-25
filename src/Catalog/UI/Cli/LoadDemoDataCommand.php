<?php

declare(strict_types=1);

namespace App\Catalog\UI\Cli;

use App\Catalog\Core\Application\Command\CreateEvent;
use App\Catalog\Core\Application\Command\CreateOrganization;
use App\Catalog\Core\Application\Command\PublishEvent;
use App\Catalog\Core\Application\Query\GetOrganizationCatalog;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use App\Shared\Exception\AbstractNotFoundException;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrganizationId;
use App\Shared\Identity\PriceTierId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:load', description: 'Load demo organizations and published events into the catalog')]
final class LoadDemoDataCommand extends Command
{
    /**
     * Keycloak organization identifier of each demo organization, by slug; must match docker/keycloak/stubandgo-realm.json.
     */
    public const array KEYCLOAK_ORGANIZATION_IDS = [
        'demo-rock-asso' => '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a01',
        'demo-theatre-ville' => '0f8c4f8e-3c7a-4b52-9a51-6d1f2b7c0a02',
    ];

    private const string FIRST_SLUG = 'demo-rock-asso';

    /**
     * @var array<string, array{name: string, events: list<array{title: string, venue: string, daysAhead: int, capacity: int}>}>
     */
    private const array ORGANIZATIONS = [
        self::FIRST_SLUG => [
            'name' => 'Rock Asso',
            'events' => [
                ['title' => 'Soirée Rock', 'venue' => 'La Cigale', 'daysAhead' => 31, 'capacity' => 200],
                ['title' => 'Concert Intimiste', 'venue' => 'Le Petit Bain', 'daysAhead' => 45, 'capacity' => 2],
            ],
        ],
        'demo-theatre-ville' => [
            'name' => 'Théâtre de la Ville',
            'events' => [
                ['title' => 'Le Malade imaginaire', 'venue' => 'Salle Molière', 'daysAhead' => 60, 'capacity' => 150],
            ],
        ],
    ];

    private const array PRICE_TIERS = [['Plein', 2500], ['Réduit', 1500], ['Gratuit', 0]];

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->isLoaded()) {
            $io->success('Demo data is already loaded.');

            return Command::SUCCESS;
        }

        $events = 0;
        foreach (self::ORGANIZATIONS as $slug => $organization) {
            $organizationId = OrganizationId::generate()->toString();
            $this->commandBus->dispatch(new CreateOrganization($organizationId, $organization['name'], $slug, 1000, self::KEYCLOAK_ORGANIZATION_IDS[$slug]));

            foreach ($organization['events'] as $event) {
                $this->createPublishedEvent($organizationId, $event);
                ++$events;
            }
        }

        $io->success(\sprintf('Demo data loaded: %d organizations, %d events.', \count(self::ORGANIZATIONS), $events));

        return Command::SUCCESS;
    }

    private function isLoaded(): bool
    {
        try {
            $this->queryBus->ask(new GetOrganizationCatalog(self::FIRST_SLUG));
        } catch (AbstractNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * @param array{title: string, venue: string, daysAhead: int, capacity: int} $event
     */
    private function createPublishedEvent(string $organizationId, array $event): void
    {
        $startsAt = \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->modify(\sprintf('+%d days', $event['daysAhead']))
            ->setTime(20, 0);
        $eventId = EventId::generate()->toString();

        $this->commandBus->dispatch(new CreateEvent(
            $eventId,
            $organizationId,
            $event['title'],
            'Événement de démonstration.',
            $event['venue'],
            $startsAt->format(\DATE_ATOM),
            $event['capacity'],
            array_map(
                static fn(array $tier): array => ['id' => PriceTierId::generate()->toString(), 'label' => $tier[0], 'priceCents' => $tier[1]],
                self::PRICE_TIERS,
            ),
        ));
        $this->commandBus->dispatch(new PublishEvent($eventId));
    }
}
