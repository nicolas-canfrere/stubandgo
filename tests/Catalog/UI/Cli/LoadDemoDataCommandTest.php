<?php

declare(strict_types=1);

namespace App\Tests\Catalog\UI\Cli;

use App\Catalog\Core\Application\Query\EventDetailView;
use App\Catalog\Core\Application\Query\GetEventDetail;
use App\Catalog\Core\Application\Query\GetOrganizationCatalog;
use App\Catalog\Core\Application\Query\OrganizationCatalogView;
use App\Catalog\Core\Application\Query\PriceTierView;
use App\Catalog\UI\Cli\LoadDemoDataCommand;
use App\Tests\Catalog\CatalogFixturesTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class LoadDemoDataCommandTest extends KernelTestCase
{
    use CatalogFixturesTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testDemoLoadCreatesPublishedEvents(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Demo data loaded: 2 organizations, 3 events.', $tester->getDisplay());

        self::assertSame([1000, 1000], self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT commission_basis_points FROM organization WHERE slug IN (?, ?) ORDER BY slug',
            ['demo-rock-asso', 'demo-theatre-ville'],
        ));

        self::assertEquals(
            LoadDemoDataCommand::KEYCLOAK_ORGANIZATION_IDS,
            self::getContainer()->get(Connection::class)->fetchAllKeyValue('SELECT slug, keycloak_id FROM organization WHERE slug IN (?, ?)', ['demo-rock-asso', 'demo-theatre-ville']),
        );

        self::assertSame(['active', 'active'], self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT status FROM organization WHERE slug IN (?, ?) ORDER BY slug',
            ['demo-rock-asso', 'demo-theatre-ville'],
        ));

        $rock = $this->catalog('demo-rock-asso');
        $theatre = $this->catalog('demo-theatre-ville');
        self::assertSame('Rock Asso', $rock->name);
        self::assertSame('Théâtre de la Ville', $theatre->name);
        self::assertCount(2, $rock->events);
        self::assertCount(1, $theatre->events);

        $details = array_map(
            fn($summary): EventDetailView => $this->detail($summary->eventId),
            [...$rock->events, ...$theatre->events],
        );
        self::assertCount(1, array_filter($details, static fn(EventDetailView $detail): bool => 2 === $detail->capacity));
        foreach ($details as $detail) {
            self::assertEquals(
                [new PriceTierView($detail->priceTiers[0]->priceTierId, 'Plein', 2500), new PriceTierView($detail->priceTiers[1]->priceTierId, 'Réduit', 1500), new PriceTierView($detail->priceTiers[2]->priceTierId, 'Gratuit', 0)],
                $detail->priceTiers,
            );
            self::assertSame('20:00', $detail->startsAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'));
            self::assertGreaterThanOrEqual(new \DateTimeImmutable(self::NOW . ' +30 days'), $detail->startsAt);
        }
    }

    public function testDemoLoadIsIdempotent(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Demo data is already loaded.', $tester->getDisplay());
        self::assertCount(2, $this->catalog('demo-rock-asso')->events);
        self::assertCount(1, $this->catalog('demo-theatre-ville')->events);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:demo:load'));
    }

    private function catalog(string $slug): OrganizationCatalogView
    {
        $catalog = $this->ask(new GetOrganizationCatalog($slug));

        return $catalog;
    }

    private function detail(string $eventId): EventDetailView
    {
        $detail = $this->ask(new GetEventDetail($eventId));

        return $detail;
    }
}
