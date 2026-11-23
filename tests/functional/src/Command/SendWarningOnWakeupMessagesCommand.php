<?php

namespace App\Command;

use App\Async\WarningOnWakeupMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-warning-on-wakeup-messages',
    description: 'Send messages whose __wakeup() raises a suppressed warning',
)]
class SendWarningOnWakeupMessagesCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::REQUIRED, 'Number of messages to send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) $input->getArgument('count');
        for ($i = 1; $i <= $count; $i++) {
            $this->messageBus->dispatch(new WarningOnWakeupMessage($i));
        }

        $output->writeln("Sent {$count} messages whose __wakeup() raises a suppressed warning.");

        return Command::SUCCESS;
    }
}
