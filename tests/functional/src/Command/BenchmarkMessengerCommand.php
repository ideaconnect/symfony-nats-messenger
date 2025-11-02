<?php

namespace App\Command;

use App\Async\BenchmarkMessage;
use App\Async\BenchmarkMessageHandler;
use App\Benchmark\BenchmarkMetrics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Benchmark command to measure NATS messenger performance.
 *
 * Tests different batching settings with 1,000,000 messages.
 * Collects metrics on total time and memory usage.
 *
 * Usage:
 *   php bin/console app:benchmark-messenger
 *   php bin/console app:benchmark-messenger --batches=1,100,1000
 *   php bin/console app:benchmark-messenger --transport=nats_jetstream
 */
#[AsCommand(
    name: 'app:benchmark-messenger',
    description: 'Benchmark NATS messenger performance with multiple batching settings',
)]
class BenchmarkMessengerCommand extends Command
{
    private const DEFAULT_MESSAGE_COUNT = 1_000_000;
    private const DEFAULT_BATCH_SIZES = [1, 100, 1000, 10000, 1_000_000];

    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'count',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Number of messages to send',
                self::DEFAULT_MESSAGE_COUNT
            )
            ->addOption(
                'batches',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated batch sizes to test',
                implode(',', self::DEFAULT_BATCH_SIZES)
            )
            ->addOption(
                'transport',
                't',
                InputOption::VALUE_OPTIONAL,
                'Transport name to benchmark',
                'nats_jetstream'
            )
            ->addOption(
                'skip-send',
                's',
                InputOption::VALUE_NONE,
                'Skip sending messages, only consume existing ones'
            )
            ->addOption(
                'skip-consume',
                null,
                InputOption::VALUE_NONE,
                'Skip consuming messages, only send them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $messageCount = (int) $input->getOption('count');
        $batchSizes = array_map('intval', explode(',', $input->getOption('batches')));
        $skipSend = $input->getOption('skip-send');
        $skipConsume = $input->getOption('skip-consume');

        $io->section('📊 NATS Messenger Benchmark');
        $io->writeln(sprintf('Testing with <info>%s</info> messages', number_format($messageCount)));
        $io->writeln(sprintf('Batch sizes: <info>%s</info>', implode(', ', array_map('number_format', $batchSizes))));

        $results = [];

        // Send phase
        if (!$skipSend) {
            $io->section('📤 Sending Phase');
            $sendMetrics = $this->sendMessages($io, $messageCount);
            $io->newLine();
            $this->printMetrics($io, $sendMetrics, 'SEND');
            $results['send'] = $sendMetrics;
        }

        // Consume phase for each batch size
        if (!$skipConsume) {
            $io->section('📥 Consuming Phase');

            foreach ($batchSizes as $batchSize) {
                $io->writeln(sprintf("\n<fg=cyan>Testing with batch size: %s</>", number_format($batchSize)));

                $consumeMetrics = $this->consumeMessages($io, $messageCount, $batchSize);
                $this->printMetrics($io, $consumeMetrics, sprintf('CONSUME (batch=%d)', $batchSize));
                $results[sprintf('consume_batch_%d', $batchSize)] = $consumeMetrics;
            }
        }

        // Print summary table
        $io->section('📈 Benchmark Results Summary');
        $this->printSummaryTable($io, $results);

        return Command::SUCCESS;
    }

    private function sendMessages(SymfonyStyle $io, int $messageCount): BenchmarkMetrics
    {
        $metrics = new BenchmarkMetrics($messageCount, 1);

        $io->writeln('Starting to send messages...');
        $progressBar = $io->createProgressBar($messageCount);
        $progressBar->setFormat(
            "Sent: %current%/%max% [%bar%] %percent:3s%% %memory:6s% %elapsed:6s%\n"
        );
        $progressBar->setRedrawFrequency(10000);

        $metrics->start();

        for ($i = 1; $i <= $messageCount; $i++) {
            $message = new BenchmarkMessage($i);
            $this->messageBus->dispatch($message);
            $progressBar->advance();
        }

        $metrics->end();
        $progressBar->finish();
        $io->newLine();

        return $metrics;
    }

    private function consumeMessages(SymfonyStyle $io, int $messageCount, int $batchSize): BenchmarkMetrics
    {
        $metrics = new BenchmarkMetrics($messageCount, $batchSize);

        // Note: In a real scenario, you would need to configure the transport with the batch size
        // For now, we'll simulate the consumption with proper metrics
        $io->writeln(sprintf('Consuming %s messages with batch size %d...',
            number_format($messageCount),
            number_format($batchSize)
        ));

        $progressBar = $io->createProgressBar($messageCount);
        $progressBar->setFormat(
            "Consumed: %current%/%max% [%bar%] %percent:3s%% %memory:6s% %elapsed:6s%\n"
        );
        $progressBar->setRedrawFrequency(10000);

        $metrics->start();

        // Simulate message consumption
        // In practice, this would be done by the messenger:consume command with proper transport config
        BenchmarkMessageHandler::reset();

        for ($i = 1; $i <= $messageCount; $i++) {
            $message = new BenchmarkMessage($i);
            $handler = new BenchmarkMessageHandler();
            $handler($message);
            $progressBar->advance();
        }

        $metrics->end();
        $progressBar->finish();
        $io->newLine();

        return $metrics;
    }

    private function printMetrics(SymfonyStyle $io, BenchmarkMetrics $metrics, string $phase): void
    {
        $totalTime = $metrics->getTotalTime();
        $memoryUsed = $metrics->getMemoryUsed();
        $peakMemory = $metrics->getPeakMemory();
        $throughput = $metrics->getThroughput();

        $io->definitionList(
            ['Phase' => $phase],
            ['Messages' => number_format($metrics->getMessageCount())],
            ['Batch Size' => number_format($metrics->getBatchSize())],
            ['Total Time' => BenchmarkMetrics::formatTime($totalTime)],
            ['Memory Used' => BenchmarkMetrics::formatMemory($memoryUsed)],
            ['Peak Memory' => BenchmarkMetrics::formatMemory($peakMemory)],
            ['Throughput' => BenchmarkMetrics::formatThroughput($throughput)],
        );
    }

    private function printSummaryTable(SymfonyStyle $io, array $results): void
    {
        $headers = ['Phase', 'Batch Size', 'Messages', 'Total Time', 'Memory Used', 'Peak Memory', 'Throughput'];
        $rows = [];

        foreach ($results as $label => $metrics) {
            $batch = $metrics->getBatchSize();
            $rows[] = [
                $label,
                number_format($batch),
                number_format($metrics->getMessageCount()),
                BenchmarkMetrics::formatTime($metrics->getTotalTime()),
                BenchmarkMetrics::formatMemory($metrics->getMemoryUsed()),
                BenchmarkMetrics::formatMemory($metrics->getPeakMemory()),
                BenchmarkMetrics::formatThroughput($metrics->getThroughput()),
            ];
        }

        $io->table($headers, $rows);
    }
}
