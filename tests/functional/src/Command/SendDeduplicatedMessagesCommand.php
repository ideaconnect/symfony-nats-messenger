<?php

namespace App\Command;

use App\Async\TestMessage;
use IDCT\NatsMessenger\Stamp\DeduplicationIdStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-deduplicated-messages',
    description: 'Send test messages with deduplication ids, then the first one again with the same id',
)]
class SendDeduplicatedMessagesCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::REQUIRED, 'Number of distinct messages to send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');

        for ($i = 1; $i <= $count; $i++) {
            $this->messageBus->dispatch(new TestMessage("Deduplicated message {$i} of {$count}", $i), [new DeduplicationIdStamp("dedup-{$i}")]);
        }

        // What an application does when a send() timed out: it dispatches the message again, as a new
        // envelope, with the same id.
        $this->messageBus->dispatch(new TestMessage("Deduplicated message 1 of {$count}", 1), [new DeduplicationIdStamp('dedup-1')]);

        $io->success("Sent {$count} messages, then message 1 again with the same deduplication id.");

        return Command::SUCCESS;
    }
}
