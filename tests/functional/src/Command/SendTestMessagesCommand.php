<?php

namespace App\Command;

use App\Async\TestMessage;
use IDCT\NatsMessenger\Stamp\NatsSubjectStamp;
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
        $this->addOption('size', null, InputOption::VALUE_REQUIRED, 'Payload size in KB (pads message content for large-message tests)', '0');
        $this->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Publish to this subject instead of the topic (NatsSubjectStamp)');
        $this->addOption('first-id', null, InputOption::VALUE_REQUIRED, 'Message id of the first message, so a second batch does not reuse ids', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');
        $delayMs = (int) $input->getOption('delay');
        $sizeKb = (int) $input->getOption('size');

        $io->title('Sending Test Messages');
        $io->info("Sending {$count} test messages..."
            . ($delayMs > 0 ? " (delay: {$delayMs}ms)" : '')
            . ($sizeKb > 0 ? " (size: {$sizeKb}KB)" : ''));

        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];
        $subject = $input->getOption('subject');
        if (is_string($subject) && $subject !== '') {
            $stamps[] = new NatsSubjectStamp($subject);
        }
        $firstId = (int) $input->getOption('first-id');

        for ($i = 1; $i <= $count; $i++) {
            $message = new TestMessage();
            $message->content = "Test message {$i} of {$count}";
            if ($sizeKb > 0) {
                // Pad the body to the requested size so the large-payload round-trip is exercised.
                $message->content = str_pad($message->content, $sizeKb * 1024, 'X');
            }
            $message->timestamp = time();
            $message->messageId = $firstId + $i - 1;

            $this->messageBus->dispatch($message, $stamps);

            if ($i % 5 === 0 || $i === $count) {
                $io->text("Sent {$i}/{$count} messages");
            }
        }

        $io->success("Successfully sent {$count} messages to the transport");

        return Command::SUCCESS;
    }
}