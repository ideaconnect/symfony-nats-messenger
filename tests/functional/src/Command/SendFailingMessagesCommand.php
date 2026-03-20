<?php

namespace App\Command;

use App\Async\FailingMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-failing-messages',
    description: 'Send failing test messages to the messenger transport',
)]
class SendFailingMessagesCommand extends Command
{
    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('count', InputArgument::REQUIRED, 'Number of messages to send')
            ->addOption('retryable', null, InputOption::VALUE_NONE, 'Messages should eventually succeed on retry');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');
        $retryable = $input->getOption('retryable');

        $io->title('Sending Failing Messages');
        $io->info(sprintf('Sending %d %s failing messages...', $count, $retryable ? 'retryable' : 'always-failing'));

        for ($i = 1; $i <= $count; $i++) {
            $message = new FailingMessage(
                content: "Failing message {$i} of {$count}",
                messageId: $i,
                shouldEventuallySucceed: $retryable,
            );

            $this->messageBus->dispatch($message);
            $io->text("Sent failing message {$i}/{$count}");
        }

        $io->success("Sent {$count} failing messages.");

        return Command::SUCCESS;
    }
}
