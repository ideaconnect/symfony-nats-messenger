<?php

namespace App\Command;

use App\Async\SlowMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-slow-message',
    description: 'Send one message whose handler works on it for the given number of seconds',
)]
class SendSlowMessageCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('seconds', InputArgument::REQUIRED, 'How long the handler works on the message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seconds = (int) $input->getArgument('seconds');

        $this->messageBus->dispatch(new SlowMessage(messageId: 1, seconds: $seconds));

        $io->success("Sent a slow message that takes {$seconds} seconds to handle.");

        return Command::SUCCESS;
    }
}
