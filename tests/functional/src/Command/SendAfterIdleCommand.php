<?php

namespace App\Command;

use App\Async\TestMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-after-idle',
    description: 'Send a test message, idle, then send another from the same process and connection',
)]
class SendAfterIdleCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('idle', null, InputOption::VALUE_REQUIRED, 'Seconds to idle between the two sends', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $idle = (int) $input->getOption('idle');

        $this->messageBus->dispatch(new TestMessage('Sent before the quiet period', 1));
        $io->text('Sent message 1');

        // A PHP process answers no pings between two transport calls, so a server that drops idle clients
        // closes this connection meanwhile.
        sleep($idle);

        try {
            $this->messageBus->dispatch(new TestMessage('Sent after the quiet period', 2));
        } catch (\Throwable $e) {
            $io->error(sprintf('The send after the quiet period failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Sent message 2 after idling for %d seconds.', $idle));

        return Command::SUCCESS;
    }
}
