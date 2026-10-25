<?php

declare(strict_types=1);

namespace App\Tests\Support\Console;

use App\Access\Core\Application\Command\ScanTicket;
use App\Access\Core\Application\Query\GetScanAttempt;
use App\Shared\Bus\CommandBusInterface;
use App\Shared\Bus\QueryBusInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Test-only command used by the concurrency tests: scans a token once the barrier file exists and prints the
 * outcome of the recorded scan attempt.
 */
#[AsCommand(name: 'test:access:scan', description: 'Scans a ticket token for the concurrency tests')]
final class DispatchScanCommand extends Command
{
    use WaitsForBarrierTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('eventId', InputArgument::REQUIRED, 'Event of the gate')
            ->addArgument('token', InputArgument::REQUIRED, 'Presented token')
            ->addOption('barrier', null, InputOption::VALUE_REQUIRED, 'File whose creation starts the scan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $eventId = $input->getArgument('eventId');
        $token = $input->getArgument('token');
        $barrier = $input->getOption('barrier');
        \assert(\is_string($eventId) && \is_string($token) && \is_string($barrier));
        $scanAttemptId = (string) new Ulid();

        $this->connection->executeQuery('SELECT 1');
        $output->writeln('ready', OutputInterface::VERBOSITY_QUIET);
        $this->waitFor($barrier);

        try {
            $this->commandBus->dispatch(new ScanTicket($scanAttemptId, $eventId, $token));
            $outcome = $this->queryBus->ask(new GetScanAttempt($scanAttemptId))->outcome;
        } catch (\Throwable $exception) {
            $outcome = \sprintf('error %s: %s', $exception::class, $exception->getMessage());
        }

        $output->writeln($outcome, OutputInterface::VERBOSITY_QUIET);

        return Command::SUCCESS;
    }
}
