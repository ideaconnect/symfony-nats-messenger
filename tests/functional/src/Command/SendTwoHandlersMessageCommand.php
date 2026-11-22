<?php

namespace App\Command;

use App\Async\TwoHandlersMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-two-handlers-message',
    description: 'Send one message to two handlers, each failing for good or once',
)]
class SendTwoHandlersMessageCommand extends Command
{
    private const FAILURES = ['for good', 'once'];

    public function __construct(private MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('first', InputArgument::REQUIRED, 'How the first handler fails: "for good" or "once"')
            ->addArgument('second', InputArgument::REQUIRED, 'How the second handler fails: "for good" or "once"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $first = (string) $input->getArgument('first');
        $second = (string) $input->getArgument('second');
        foreach ([$first, $second] as $failing) {
            if (!in_array($failing, self::FAILURES, true)) {
                $output->writeln(sprintf('<error>A handler fails "for good" or "once", not "%s".</error>', $failing));

                return Command::INVALID;
            }
        }

        $this->messageBus->dispatch(new TwoHandlersMessage($first, $second));
        $output->writeln(sprintf('Sent a message to two handlers, the first failing %s and the second %s.', $first, $second));

        return Command::SUCCESS;
    }
}
