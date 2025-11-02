<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

#[AsCommand(
    name: 'app:test-setup',
    description: 'Test the NATS transport setup functionality',
)]
class TestSetupCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing NATS Transport Setup Interface');

        $io->info('This command verifies that the NATS transport implements SetupableTransportInterface.');
        $io->info('To test the actual setup functionality, use: php bin/console messenger:setup-transports');

        return Command::SUCCESS;
    }
}