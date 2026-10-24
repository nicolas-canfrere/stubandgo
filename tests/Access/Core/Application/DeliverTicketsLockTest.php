<?php

declare(strict_types=1);

namespace App\Tests\Access\Core\Application;

use App\Access\Core\Application\Command\DeliverTickets;
use App\Access\Core\Application\Command\DeliverTicketsHandler;
use App\Access\Core\Domain\EventDisplayGatewayInterface;
use App\Access\Core\Domain\TicketBundle;
use App\Access\Core\Domain\TicketBundleRepositoryInterface;
use App\Access\Core\Domain\TicketMailerInterface;
use App\Access\Core\Domain\ValueObject\EventDisplayInfo;
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
use Symfony\Component\Clock\MockClock;

/**
 * Pins the locking read of the delivery: while another transaction holds the bundle row, the handler must wait on
 * it (and here time out) instead of reading the bundle and sending. Plain get() would send the email.
 *
 * The other connection only sees committed rows: this test bypasses the DAMA rollback and deletes what it created.
 */
#[SkipDatabaseRollback]
final class DeliverTicketsLockTest extends KernelTestCase
{
    public function testDeliveryWaitsForTheLockOnTheBundleRow(): void
    {
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $bundles = $container->get(TicketBundleRepositoryInterface::class);
        $bundle = TicketBundle::issue(
            OrderId::generate(),
            EventId::generate(),
            EmailAddress::fromString('buyer@example.com'),
            [new OrderLine(PriceTierId::generate(), Quantity::of(1))],
            new RandomTicketTokenGenerator(),
        );
        $orderId = $bundle->orderId()->toString();
        $bundles->save($bundle);
        $mailer = new class implements TicketMailerInterface {
            public int $sent = 0;

            public function send(TicketBundle $bundle, EventDisplayInfo $event): void
            {
                ++$this->sent;
            }
        };
        $events = new class implements EventDisplayGatewayInterface {
            public function forEvent(EventId $eventId): EventDisplayInfo
            {
                return EventDisplayInfo::of('Concert', 'La Cigale', new \DateTimeImmutable('2026-12-31T19:30:00+00:00'), []);
            }
        };
        $handler = new DeliverTicketsHandler($bundles, $events, $mailer, new MockClock('2026-10-24T12:00:00+00:00'));
        $other = DriverManager::getConnection($connection->getParams());

        try {
            $other->beginTransaction();
            $other->fetchOne('SELECT order_id FROM ticket_bundle WHERE order_id = ? FOR UPDATE', [$orderId]);
            $connection->executeStatement("SET lock_timeout = '200ms'");

            try {
                $handler(new DeliverTickets($orderId));
                self::fail('The delivery must wait for the lock held on the bundle row.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }

            self::assertSame(0, $mailer->sent);
            self::assertFalse($bundles->get($bundle->orderId())->isDelivered());
        } finally {
            $connection->executeStatement('RESET lock_timeout');
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
            $connection->executeStatement('DELETE FROM admission_right WHERE ticket_id IN (SELECT id FROM ticket WHERE order_id = ?)', [$orderId]);
            $connection->executeStatement('DELETE FROM ticket WHERE order_id = ?', [$orderId]);
            $connection->executeStatement('DELETE FROM ticket_bundle WHERE order_id = ?', [$orderId]);
        }
    }
}
