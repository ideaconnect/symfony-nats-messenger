<?php

namespace App\Command;

use App\Async\TestMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');

        $io->title('Sending Test Messages');
        $io->info("Sending {$count} test messages...");

        for ($i = 1; $i <= $count; $i++) {
            $message = new TestMessage();
            $message->content = "Test message {$i} of {$count}";
            $message->timestamp = time();
            $message->messageId = $i;

            $this->messageBus->dispatch($message);

            if ($i % 5 === 0 || $i === $count) {
                $io->text("Sent {$i}/{$count} messages");
            }
        }

        $io->success("Successfully sent {$count} messages to the transport");

        return Command::SUCCESS;
    }
}