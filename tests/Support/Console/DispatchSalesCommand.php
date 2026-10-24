<?php

declare(strict_types=1);

namespace App\Tests\Support\Console;

use App\Sales\Core\Application\Command\ExpireOrder;
use App\Sales\Core\Application\Command\PayOrder;
use App\Sales\Core\Application\Command\PlaceOrder;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Exception\AbstractDomainException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test-only command used by the concurrency tests: dispatches one Sales command once the barrier file exists and
 * prints "ok" or the short name of the domain exception it raised.
 */
#[AsCommand(name: 'test:sales:dispatch', description: 'Dispatches a Sales command for the concurrency tests')]
final class DispatchSalesCommand extends Command
{
    private const int BARRIER_TIMEOUT_MICROSECONDS = 60_000_000;
    private const int POLL_MICROSECONDS = 200;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::REQUIRED, 'place-order, pay-order or expire-order')
            ->addArgument('arguments', InputArgument::IS_ARRAY, 'Arguments of the message')
            ->addOption('barrier', null, InputOption::VALUE_REQUIRED, 'File whose creation starts the dispatch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $arguments */
        $arguments = $input->getArgument('arguments');
        $message = match ($input->getArgument('message')) {
            'place-order' => new PlaceOrder($arguments[0], $arguments[1], 'buyer@example.com', [['priceTierId' => $arguments[2], 'quantity' => (int) $arguments[3]]]),
            'pay-order' => new PayOrder($arguments[0], $arguments[1]),
            'expire-order' => new ExpireOrder($arguments[0]),
            default => throw new \InvalidArgumentException('Unknown message.'),
        };

        $barrier = $input->getOption('barrier');
        \assert(\is_string($barrier));

        $this->connection->executeQuery('SELECT 1');
        $output->writeln('ready', OutputInterface::VERBOSITY_QUIET);
        $this->waitFor($barrier);

        try {
            $this->commandBus->dispatch($message);
            $outcome = 'ok';
        } catch (AbstractDomainException $exception) {
            $outcome = new \ReflectionClass($exception)->getShortName();
        } catch (\Throwable $exception) {
            $outcome = \sprintf('error %s: %s', $exception::class, $exception->getMessage());
        }

        $output->writeln($outcome, OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }

    private function waitFor(string $barrier): void
    {
        $waited = 0;
        clearstatcache();

        while (!file_exists($barrier)) {
            if ($waited >= self::BARRIER_TIMEOUT_MICROSECONDS) {
                throw new \RuntimeException('The barrier never opened.');
            }

            usleep(self::POLL_MICROSECONDS);
            $waited += self::POLL_MICROSECONDS;
            clearstatcache();
        }
    }
}
