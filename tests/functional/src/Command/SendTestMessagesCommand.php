<?php

namespace App\Command;

use App\Async\TestMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsCommand(
    name: 'app:send-test-messages',
    description: 'Send test messages to the messenger transport',
)]
class SendTestMessagesCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::REQUIRED, 'Number of messages to send');
        $this->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay in milliseconds before delivery', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');
        $delayMs = (int) $input->getOption('delay');

        $io->title('Sending Test Messages');
        $io->info("Sending {$count} test messages..." . ($delayMs > 0 ? " (delay: {$delayMs}ms)" : ''));

        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

        for ($i = 1; $i <= $count; $i++) {
            $message = new TestMessage();
            $message->content = "Test message {$i} of {$count}";
            $message->timestamp = time();
            $message->messageId = $i;

            $this->messageBus->dispatch($message, $stamps);

            if ($i % 5 === 0 || $i === $count) {
                $io->text("Sent {$i}/{$count} messages");
            }
        }

        $io->success("Successfully sent {$count} messages to the transport");

        return Command::SUCCESS;
    }
}