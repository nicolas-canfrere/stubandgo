<?php

declare(strict_types=1);

namespace App\Tests\Access\Infrastructure;

use App\Access\Core\Domain\AdmissionRight;
use App\Access\Core\Domain\Exception\TicketBundleNotFoundException;
use App\Access\Core\Domain\Ticket;
use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Core\Domain\ValueObject\TicketId;
use App\Access\Infrastructure\RandomTicketTokenGenerator;
use App\Shared\Identity\EventId;
use App\Shared\Identity\OrderId;
use App\Shared\Identity\PriceTierId;
use App\Shared\ValueObject\EmailAddress;
use App\Shared\ValueObject\OrderLine;
use App\Shared\ValueObject\Quantity;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalTicketBundleRepositoryTest extends KernelTestCase
{
    private TicketBundleRepositoryInterface $bundles;

    protected function setUp(): void
    {
        $this->bundles = self::getContainer()->get(TicketBundleRepositoryInterface::class);
    }

    public function testSavedBundleIsRebuiltIdentically(): void
    {
        $bundle = $this->bundle([2, 1]);
        $this->bundles->save($bundle);

        $loaded = $this->bundles->get($bundle->orderId());

        self::assertEquals($bundle, $loaded);
        self::assertCount(3, $loaded->tickets());
        self::assertNull($loaded->deliveredAt());
    }

    public function testDeliveredAtIsPersistedInUtc(): void
    {
        $bundle = $this->bundle([1]);
        $this->bundles->save($bundle);
        $bundle->markDelivered(new \DateTimeImmutable('2026-10-24T14:00:00.123456+02:00'));

        $this->bundles->save($bundle);

        $loaded = $this->bundles->get($bundle->orderId());
        self::assertTrue($loaded->isDelivered());
        self::assertEquals(new \DateTimeImmutable('2026-10-24T12:00:00.123456+00:00'), $loaded->deliveredAt());
        self::assertCount(1, $loaded->tickets());
    }

    public function testSavingTwiceKeepsOneBundle(): void
    {
        $bundle = $this->bundle([2]);

        $this->bundles->save($bundle);
        $this->bundles->save($bundle);

        self::assertCount(2, $this->bundles->get($bundle->orderId())->tickets());
    }

    public function testUsedAdmissionRightIsRebuilt(): void
    {
        $bundle = $this->bundle([1]);
        $this->bundles->save($bundle);
        $token = $bundle->tickets()[0]->admissionRight()->token()->toString();
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE admission_right SET used_at = '2026-10-24 12:00:00+00' WHERE token = ?", [$token]);

        $right = $this->bundles->get($bundle->orderId())->tickets()[0]->admissionRight();

        self::assertTrue($right->isUsed());
        self::assertEquals(new \DateTimeImmutable('2026-10-24T12:00:00+00:00'), $right->usedAt());
    }

    public function testTicketsComeBackInIssuingOrder(): void
    {
        $bundle = $this->bundle([3]);
        $this->bundles->save($bundle);

        $ids = array_map(static fn(Ticket $ticket): string => $ticket->id()->toString(), $this->bundles->get($bundle->orderId())->tickets());

        self::assertSame(array_map(static fn(Ticket $ticket): string => $ticket->id()->toString(), $bundle->tickets()), $ids);
    }

    public function testGetForUpdateLoadsTheBundle(): void
    {
        $bundle = $this->bundle([1]);
        $this->bundles->save($bundle);

        self::assertEquals($bundle, $this->bundles->getForUpdate($bundle->orderId()));
    }

    /**
     * Another connection must see the lock: this test commits its rows (outside the DAMA transaction) and deletes them.
     */
    #[SkipDatabaseRollback]
    public function testGetForUpdateLocksTheBundleRowUntilTheTransactionEnds(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $bundle = $this->bundle([1]);
        $this->bundles->save($bundle);
        $orderId = $bundle->orderId()->toString();
        $other = DriverManager::getConnection($connection->getParams());

        try {
            $connection->beginTransaction();
            $this->bundles->getForUpdate($bundle->orderId());

            try {
                $other->fetchOne('SELECT order_id FROM ticket_bundle WHERE order_id = ? FOR UPDATE NOWAIT', [$orderId]);
                self::fail('The bundle row must be locked by the first connection.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }

            $connection->rollBack();
            self::assertSame($orderId, $other->fetchOne('SELECT order_id FROM ticket_bundle WHERE order_id = ? FOR UPDATE NOWAIT', [$orderId]));
        } finally {
            $other->close();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->executeStatement('DELETE FROM admission_right WHERE ticket_id IN (SELECT id FROM ticket WHERE order_id = ?)', [$orderId]);
            $connection->executeStatement('DELETE FROM ticket WHERE order_id = ?', [$orderId]);
            $connection->executeStatement('DELETE FROM ticket_bundle WHERE order_id = ?', [$orderId]);
        }
    }

    public function testGetUnknownBundleFails(): void
    {
        $this->expectException(TicketBundleNotFoundException::class);

        $this->bundles->get(OrderId::generate());
    }

    public function testGetForUpdateUnknownBundleFails(): void
    {
        $this->expectException(TicketBundleNotFoundException::class);

        $this->bundles->getForUpdate(OrderId::generate());
    }

    public function testTokenIsUnique(): void
    {
        $first = $this->bundle([1]);
        $this->bundles->save($first);
        $token = $first->tickets()[0]->admissionRight()->token();
        $duplicate = TicketBundle::reconstitute(
            OrderId::generate(),
            EventId::generate(),
            EmailAddress::fromString('other@example.com'),
            [new Ticket(TicketId::generate(), PriceTierId::generate(), new AdmissionRight($token, EventId::generate()))],
            null,
        );

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->bundles->save($duplicate);
    }

    /**
     * @param list<int> $quantities one line per quantity
     */
    private function bundle(array $quantities): TicketBundle
    {
        return TicketBundle::issue(
            OrderId::generate(),
            EventId::generate(),
            EmailAddress::fromString('buyer@example.com'),
            array_map(static fn(int $quantity): OrderLine => new OrderLine(PriceTierId::generate(), Quantity::of($quantity)), $quantities),
            new RandomTicketTokenGenerator(),
        );
    }
}
