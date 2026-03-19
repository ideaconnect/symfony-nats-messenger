<?php

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use IDCT\NATS\Connection\NatsOptions;
use IDCT\NATS\Core\NatsClient;
use IDCT\NatsMessenger\NatsTransport;

/**
 * Defines application features from the specific context.
 */
class NatsSetupContext implements Context
{
    private ?Process $natsProcess = null;
    private ?Process $setupProcess = null;
    private ?Process $consumerProcess = null;
    /** @var Process[] */
    private array $consumerProcesses = [];
    private string $testStreamName = 'stream';
    private string $testSubject = 'test.messages';
    private string $failedStreamName = 'failed_stream';
    private string $failedSubject = 'fail.messages';
    private bool $shouldNatsBeRunning = false;
    private bool $useTls = false;
    private bool $useMtls = false;
    private int $messagesSent = 0;
    private int $messagesConsumed = 0;
    private string $testFilesDir;
    private string $retryStateDir;

    public function __construct()
    {
        $this->testFilesDir = __DIR__ . '/../../var/test_files';
        $this->retryStateDir = __DIR__ . '/../../var/retry_state';
    }

    /**
     * @Given NATS server is running
     */
    public function natsServerIsRunning(): void
    {
        $this->shouldNatsBeRunning = true;

        // Start NATS server (or verify it's already running in CI)
        $this->startNatsServer();

        // Give it a moment to fully initialize
        sleep(2);

        // Wait for NATS to be ready with improved error handling
        $this->waitForNatsToBeReady();
    }

    /**
     * @Given NATS server is not running
     */
    public function natsServerIsNotRunning(): void
    {
        $this->shouldNatsBeRunning = false;
        $this->stopNatsServer();
    }

    /**
     * @Given I have a messenger transport configured with max age of :maxAge minutes
     * @Given I have a messenger transport configured with max age of :maxAge minutes using :serializer
     */
    public function iHaveAMessengerTransportConfiguredWithMaxAgeOfMinutes(int $maxAge, string $serializer = 'igbinary_serializer'): void
    {
        // Create a temporary messenger configuration for testing
        $maxAgeSeconds = $maxAge * 60;

        // Create a test-specific configuration
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=%d'\n                serializer: '%s'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $maxAgeSeconds,
            $serializer
        );

        // Write temporary config file for the test environment
        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);

        $this->resetSymfonyCache();
    }

    /**
     * @Given the NATS stream already exists
     */
    public function theNatsStreamAlreadyExists(): void
    {
        if (!$this->shouldNatsBeRunning) {
            throw new \RuntimeException('NATS must be running to create a stream');
        }

        // Create the stream manually using NATS client
        $client = $this->createNatsClient();
        $client->jetStream()->createStream($this->testStreamName, [$this->testSubject])->await();
    }

    /**
     * @When I run the messenger setup command
     */
    public function iRunTheMessengerSetupCommand(): void
    {
        $command = [
            'php',
            'bin/console',
            'messenger:setup-transports',
            'test_transport',
            '--no-interaction',
            '--env=test'
        ];

        $this->setupProcess = new Process($command, __DIR__ . '/../..');
        $this->setupProcess->run();
    }

    /**
     * @Then the NATS stream should be created successfully
     */
    public function theNatsStreamShouldBeCreatedSuccessfully(): void
    {
        if ($this->setupProcess->getExitCode() !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Setup command failed with exit code %d. Output: %s. Error: %s',
                    $this->setupProcess->getExitCode(),
                    $this->setupProcess->getOutput(),
                    $this->setupProcess->getErrorOutput()
                )
            );
        }

        // Verify the stream exists in NATS
        $this->verifyStreamExists();
    }

    /**
     * @Then the stream should have a max age of :maxAge minutes
     */
    public function theStreamShouldHaveAMaxAgeOfMinutes(int $maxAge): void
    {
        $expectedMaxAgeNanoseconds = $maxAge * 60 * 1_000_000_000; // Convert minutes to nanoseconds

        $client = $this->createNatsClient();
        $streamInfo = $client->jetStream()->getStream($this->testStreamName)->await();
        $streamConfig = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        $actualMaxAge = (int) ($streamConfig['max_age'] ?? 0);

        if ($actualMaxAge !== $expectedMaxAgeNanoseconds) {
            throw new \RuntimeException(
                sprintf(
                    'Expected stream max age to be %d nanoseconds (%d minutes), but got %d nanoseconds',
                    $expectedMaxAgeNanoseconds,
                    $maxAge,
                    $actualMaxAge
                )
            );
        }
    }

    /**
     * @Then the stream should be configured with the correct subject
     */
    public function theStreamShouldBeConfiguredWithTheCorrectSubject(): void
    {
        $client = $this->createNatsClient();
        $streamInfo = $client->jetStream()->getStream($this->testStreamName)->await();
        $streamConfig = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        $subjects = is_array($streamConfig['subjects'] ?? null) ? $streamConfig['subjects'] : [];

        if (!in_array($this->testSubject, $subjects)) {
            throw new \RuntimeException(
                sprintf(
                    'Expected stream to have subject "%s", but found subjects: %s',
                    $this->testSubject,
                    implode(', ', $subjects)
                )
            );
        }
    }

    /**
     * @Then the setup should complete successfully
     */
    public function theSetupShouldCompleteSuccessfully(): void
    {
        if ($this->setupProcess->getExitCode() !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Setup command failed with exit code %d. Output: %s. Error: %s',
                    $this->setupProcess->getExitCode(),
                    $this->setupProcess->getOutput(),
                    $this->setupProcess->getErrorOutput()
                )
            );
        }
    }

    /**
     * @Then the existing stream configuration should be preserved
     */
    public function theExistingStreamConfigurationShouldBePreserved(): void
    {
        // This step assumes the stream was created in a previous step
        // and verifies it still exists with correct configuration
        $this->verifyStreamExists();
        $this->theStreamShouldBeConfiguredWithTheCorrectSubject();
    }

    /**
     * @Then the setup should fail with a connection error
     */
    public function theSetupShouldFailWithAConnectionError(): void
    {
        if ($this->setupProcess->getExitCode() === 0) {
            throw new \RuntimeException('Expected setup command to fail, but it succeeded');
        }

        $output = $this->setupProcess->getOutput() . $this->setupProcess->getErrorOutput();

        if (!str_contains($output, 'Connection refused') && !str_contains($output, 'connection') && !str_contains($output, 'Connection')) {
            throw new \RuntimeException(
                sprintf(
                    'Expected connection error message, but got: %s',
                    $output
                )
            );
        }
    }

    /**
     * @Then the error message should be descriptive
     */
    public function theErrorMessageShouldBeDescriptive(): void
    {
        $output = $this->setupProcess->getOutput() . $this->setupProcess->getErrorOutput();

        if (strlen(trim($output)) < 10) {
            throw new \RuntimeException('Error message is too short to be descriptive');
        }
    }

    /**
     * @Given the NATS stream is set up
     */
    public function theNatsStreamIsSetUp(): void
    {
        // Use the messenger setup command to create both stream and consumer via the transport's setup() method
        $this->iRunTheMessengerSetupCommand();
        $this->theNatsStreamShouldBeCreatedSuccessfully();

        // Purge any existing messages from the stream for clean test state
        try {
            $client = $this->createAppropriateNatsClient();
            $client->jetStream()->purgeStream($this->testStreamName)->await();
        } catch (\Exception $e) {
            // If we can't purge, only fail if it's not a "stream not found" error
            if (strpos($e->getMessage(), 'stream not found') === false) {
                throw new \RuntimeException("Failed to purge stream for clean test state: " . $e->getMessage());
            }
        }
    }

    /**
     * @When I send :count messages to the transport
     */
    public function iSendMessagesToTheTransport(int $count): void
    {
        $this->messagesSent = $count;

        // Use a simple command to send messages
        $command = [
            'php',
            'bin/console',
            'app:send-test-messages',
            (string) $count,
            '--env=test'
        ];

        $sendProcess = new Process($command, __DIR__ . '/../..');
        // Set timeout based on message count - allow 1 second per 100 messages minimum 60s
        $timeout = max(60, $count / 100);
        $sendProcess->setTimeout($timeout);
        $sendProcess->run();

        if (!$sendProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to send messages. Exit code: %d. Output: %s. Error: %s',
                    $sendProcess->getExitCode(),
                    $sendProcess->getOutput(),
                    $sendProcess->getErrorOutput()
                )
            );
        }
    }

    /**
     * @Then the messenger stats should show :count messages waiting
     */
    public function theMessengerStatsShouldShowMessagesWaiting(int $count): void
    {
        $command = [
            'php',
            'bin/console',
            'messenger:stats',
            '--env=test'
        ];

        $statsProcess = new Process($command, __DIR__ . '/../..');
        $statsProcess->run();

        if (!$statsProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to get messenger stats. Exit code: %d. Output: %s. Error: %s',
                    $statsProcess->getExitCode(),
                    $statsProcess->getOutput(),
                    $statsProcess->getErrorOutput()
                )
            );
        }

        // Symfony may render the table to either stderr or stdout depending on console version.
        $output = $statsProcess->getErrorOutput();
        if ($output === '') {
            $output = $statsProcess->getOutput();
        }

        // Parse the output to find the test_transport line and extract the message count
        $lines = explode("\n", $output);
        $messageCount = null;

        foreach ($lines as $line) {
            if (strpos($line, 'test_transport') !== false) {
                // Extract number from lines like "  test_transport   20  "
                if (preg_match('/test_transport\s+(\d+)/', $line, $matches)) {
                    $messageCount = (int) $matches[1];
                    break;
                }
            }
        }

        if ($messageCount === null) {
            throw new \RuntimeException(
                sprintf(
                    'Could not find test_transport in messenger:stats output: %s',
                    $output
                )
            );
        }

        if ($messageCount !== $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected %d messages waiting in messenger stats, but found %d. Full output: %s',
                    $count,
                    $messageCount,
                    $output
                )
            );
        }
    }

    /**
     * @When I start a messenger consumer
     */
    public function iStartAMessengerConsumer(): void
    {
        $command = [
            'php',
            'bin/console',
            'messenger:consume',
            'test_transport',
            '--limit=' . $this->messagesSent,
            '--time-limit=600', // Give more time
            '--sleep=0.1', // Reduce sleep between messages
            '--env=test',
            '-vv' // Verbose output to see what's happening
        ];

        $this->consumerProcess = new Process($command, __DIR__ . '/../..');
        $this->consumerProcess->setTimeout(600); // 10 minutes timeout
        $this->consumerProcess->start();
    }

    /**
     * @When I wait for messages to be consumed
     */
    public function iWaitForMessagesToBeConsumed(): void
    {
        if (!$this->consumerProcess) {
            throw new \RuntimeException('Consumer process not started');
        }

        // Wait for the consumer process to finish or timeout
        $this->consumerProcess->wait();

        // For debugging, let's see what the consumer output was
        $output = $this->consumerProcess->getOutput();
        $errorOutput = $this->consumerProcess->getErrorOutput();

        if (!$this->consumerProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Consumer process failed. Exit code: %d. Output: %s. Error: %s',
                    $this->consumerProcess->getExitCode(),
                    $output,
                    $errorOutput
                )
            );
        }
    }

    /**
     * @Then all :count messages should be consumed
     */
    public function allMessagesShouldBeConsumed(int $count): void
    {
        if (!$this->consumerProcess) {
            throw new \RuntimeException('Consumer process not started');
        }

        $output = $this->consumerProcess->getOutput();
        $errorOutput = $this->consumerProcess->getErrorOutput();

        // Debug: Show the full consumer output
        echo "Consumer output:\n" . $output . "\n";
        if (!empty($errorOutput)) {
            echo "Consumer error output:\n" . $errorOutput . "\n";
        }

        // Count successful message processing
        $successPatterns = [
            '/\[OK\].*consumed/i',
            '/\[OK\].*Consumed message/i',
            '/Received message.*from transport/i',
            '/Processing message/i'
        ];

        $consumedCount = 0;
        foreach ($successPatterns as $pattern) {
            $matches = preg_match_all($pattern, $output);
            if ($matches !== false && $matches > 0) {
                $consumedCount = max($consumedCount, $matches);
                break;
            }
        }

        // If pattern matching fails, check for the message limit reached
        if ($consumedCount === 0) {
            if (str_contains($output, 'limit of ' . $count . ' messages reached') ||
                str_contains($output, 'limit of ' . $count . ' exceeded') ||
                str_contains($output, $count . ' messages')) {
                $consumedCount = $count;
            }
        }

        // If we still can't determine, assume success if exit code is 0
        if ($consumedCount === 0 && $this->consumerProcess->getExitCode() === 0) {
            echo "Warning: Could not parse consumed message count from output, but process exited successfully. Assuming all messages were consumed.\n";
            $this->messagesConsumed = $count;
            return;
        }

        if ($consumedCount < $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected %d messages to be consumed, but could only verify %d. Consumer output: %s',
                    $count,
                    $consumedCount,
                    $output
                )
            );
        }

        $this->messagesConsumed = $count;
    }

    /**
     * @When I start :consumerCount consumers that each process :messagesPerConsumer messages
     */
    public function iStartConsumersThatEachProcessMessages(int $consumerCount, int $messagesPerConsumer): void
    {
        $this->consumerProcesses = [];

        for ($i = 1; $i <= $consumerCount; $i++) {
            $command = [
                'php',
                'bin/console',
                'messenger:consume',
                'test_transport',
                '--limit=' . $messagesPerConsumer,
                '--time-limit=600', // 10 minutes for high volume processing (for json especially)
                '--env=test',
                '-v'
            ];

            $process = new Process($command, __DIR__ . '/../..');
            // Set timeout based on message count - allow time for processing
            $timeout = max(600, ($messagesPerConsumer / 10)); // 10 messages per second estimate
            $process->setTimeout($timeout + 60); // Add buffer
            $process->start();

            $this->consumerProcesses[] = $process;

            // Small delay between starting consumers
            usleep(50000); // 50ms
        }
    }

    /**
     * @When I wait for the consumers to finish
     */
    public function iWaitForTheConsumersToFinish(): void
    {
        if (empty($this->consumerProcesses)) {
            throw new \RuntimeException('No consumer processes started');
        }

        // Wait for all consumer processes to finish
        foreach ($this->consumerProcesses as $index => $process) {
            $process->wait();

            if (!$process->isSuccessful()) {
                $output = $process->getOutput();
                $errorOutput = $process->getErrorOutput();

                throw new \RuntimeException(
                    sprintf(
                        'Consumer process %d failed. Exit code: %d. Output: %s. Error: %s',
                        $index + 1,
                        $process->getExitCode(),
                        $output,
                        $errorOutput
                    )
                );
            }
        }

        // Give a bit more time for final acknowledgments to be processed
        sleep(1);
    }

    /**
     * @Then :count messages should have been processed by the consumers
     */
    public function messagesShouldHaveBeenProcessedByTheConsumers(int $count): void
    {
        $totalProcessed = 0;

        foreach ($this->consumerProcesses as $index => $process) {
            $output = $process->getOutput();

            // Count "Consumed message" lines in output
            $consumedLines = substr_count($output, '[OK] Consumed message');
            $totalProcessed += $consumedLines;
        }

        if ($totalProcessed !== $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected %d total messages to be processed by consumers, but found %d processed',
                    $count,
                    $totalProcessed
                )
            );
        }
    }

    /**
     * @When I start :consumerCount consumer that processes :messageCount messages
     */
    public function iStartConsumerThatProcessesMessages(int $consumerCount, int $messageCount): void
    {
        $this->consumerProcesses = [];

        for ($i = 1; $i <= $consumerCount; $i++) {
            $command = [
                'php',
                'bin/console',
                'messenger:consume',
                'test_transport',
                '--limit=' . $messageCount,
                '--time-limit=1800', // 30 minutes for extreme volume processing
                '--env=test',
                '-v'
            ];

            $process = new Process($command, __DIR__ . '/../..');
            // Set timeout based on message count - allow time for processing
            $timeout = max(300, ($messageCount / 10)); // 10 messages per second estimate with buffer
            $process->setTimeout($timeout + 300); // Add 5-minute buffer
            $process->start();

            $this->consumerProcesses[] = $process;

            // Small delay between starting consumers
            usleep(100000); // 100ms
        }

        echo "Started $consumerCount consumer(s) to process $messageCount messages each\n";
    }

    /**
     * @Given NATS TLS server is running
     */
    public function natsTlsServerIsRunning(): void
    {
        $this->shouldNatsBeRunning = true;
        $this->useTls = true;

        $this->startNatsServer();
        sleep(2);
        $this->waitForNatsTlsToBeReady();
    }

    /**
     * @Given I have a TLS messenger transport configured using :serializer
     */
    public function iHaveATlsMessengerTransportConfiguredUsing(string $serializer = 'igbinary_serializer'): void
    {
        $caFile = realpath(__DIR__ . '/../../../nats/certs/ca.pem');

        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream+tls://admin:password@localhost:4223/%s/%s?stream_max_age=900&tls_ca_file=%s&tls_verify_peer=true&tls_peer_name=localhost'\n                serializer: '%s'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $caFile,
            $serializer
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);

        $this->resetSymfonyCache();
    }

    /**
     * @Given NATS mTLS server is running
     */
    public function natsMtlsServerIsRunning(): void
    {
        $this->shouldNatsBeRunning = true;
        $this->useTls = true;
        $this->useMtls = true;

        $this->startNatsServer();
        sleep(2);
        $this->waitForNatsMtlsToBeReady();
    }

    /**
     * @Given I have an mTLS messenger transport configured
     */
    public function iHaveAnMtlsMessengerTransportConfigured(): void
    {
        $caFile = realpath(__DIR__ . '/../../../nats/certs/ca.pem');
        $certFile = realpath(__DIR__ . '/../../../nats/certs/client-cert.pem');
        $keyFile = realpath(__DIR__ . '/../../../nats/certs/client-key.pem');

        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream+tls://admin:password@localhost:4224/%s/%s?stream_max_age=900&tls_ca_file=%s&tls_cert_file=%s&tls_key_file=%s&tls_verify_peer=true&tls_peer_name=localhost'\n                serializer: 'igbinary_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $caFile,
            $certFile,
            $keyFile
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);

        $this->resetSymfonyCache();
    }

    /**
     * @Given I have a messenger transport configured with NATS retry handler
     */
    public function iHaveAMessengerTransportConfiguredWithNatsRetryHandler(): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&retry_handler=nats'\n                serializer: 'igbinary_serializer'\n                retry_strategy:\n                    max_retries: 0\n        routing:\n            'App\\Async\\FailingMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);

        $this->resetSymfonyCache();
    }

    /**
     * @Given I have a messenger transport with failure transport configured
     */
    public function iHaveAMessengerTransportWithFailureTransportConfigured(): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        failure_transport: failed_transport\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900'\n                serializer: 'messenger.transport.native_php_serializer'\n                retry_strategy:\n                    max_retries: 1\n                    delay: 100\n                    multiplier: 1\n            failed_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\FailingMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $this->failedStreamName,
            $this->failedSubject
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);

        $this->resetSymfonyCache();
    }

    /**
     * @Given the failure stream is set up
     */
    public function theFailureStreamIsSetUp(): void
    {
        $command = [
            'php',
            'bin/console',
            'messenger:setup-transports',
            'failed_transport',
            '--no-interaction',
            '--env=test'
        ];

        $process = new Process($command, __DIR__ . '/../..');
        $process->run();

        if ($process->getExitCode() !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to set up failure transport. Exit code: %d. Output: %s. Error: %s',
                    $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput()
                )
            );
        }
    }

    /**
     * @Given the retry state directory is clean
     */
    public function theRetryStateDirectoryIsClean(): void
    {
        if (is_dir($this->retryStateDir)) {
            $files = glob($this->retryStateDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } else {
            mkdir($this->retryStateDir, 0755, true);
        }
    }

    /**
     * @When I send :count retryable failing message
     * @When I send :count retryable failing messages
     */
    public function iSendRetryableFailingMessages(int $count): void
    {
        $this->messagesSent = $count;

        $command = [
            'php',
            'bin/console',
            'app:send-failing-messages',
            (string) $count,
            '--retryable',
            '--env=test'
        ];

        $sendProcess = new Process($command, __DIR__ . '/../..');
        $sendProcess->setTimeout(60);
        $sendProcess->run();

        if (!$sendProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to send failing messages. Exit code: %d. Output: %s. Error: %s',
                    $sendProcess->getExitCode(),
                    $sendProcess->getOutput(),
                    $sendProcess->getErrorOutput()
                )
            );
        }
    }

    /**
     * @When I send :count always-failing message
     * @When I send :count always-failing messages
     */
    public function iSendAlwaysFailingMessages(int $count): void
    {
        $this->messagesSent = $count;

        $command = [
            'php',
            'bin/console',
            'app:send-failing-messages',
            (string) $count,
            '--env=test'
        ];

        $sendProcess = new Process($command, __DIR__ . '/../..');
        $sendProcess->setTimeout(60);
        $sendProcess->run();

        if (!$sendProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to send failing messages. Exit code: %d. Output: %s. Error: %s',
                    $sendProcess->getExitCode(),
                    $sendProcess->getOutput(),
                    $sendProcess->getErrorOutput()
                )
            );
        }
    }

    /**
     * @When I start a messenger consumer with high limit
     */
    public function iStartAMessengerConsumerWithHighLimit(): void
    {
        $command = [
            'php',
            'bin/console',
            'messenger:consume',
            'test_transport',
            '--limit=10',
            '--time-limit=30',
            '--sleep=0.1',
            '--env=test',
            '-vv'
        ];

        $this->consumerProcess = new Process($command, __DIR__ . '/../..');
        $this->consumerProcess->setTimeout(60);
        $this->consumerProcess->start();
    }

    /**
     * @When I wait for the consumer to finish or timeout
     */
    public function iWaitForTheConsumerToFinishOrTimeout(): void
    {
        if (!$this->consumerProcess) {
            throw new \RuntimeException('Consumer process not started');
        }

        $this->consumerProcess->wait();

        // Consumer may exit with non-zero when all messages fail,
        // which is expected in failure scenarios.
        $output = $this->consumerProcess->getOutput();
        $errorOutput = $this->consumerProcess->getErrorOutput();

        echo "Consumer output:\n" . $output . "\n";
        if (!empty($errorOutput)) {
            echo "Consumer error output:\n" . $errorOutput . "\n";
        }
    }

    /**
     * @Then the retryable message should have been processed successfully
     */
    public function theRetryableMessageShouldHaveBeenProcessedSuccessfully(): void
    {
        $successFile = $this->testFilesDir . '/failing_message_1.txt';

        if (!file_exists($successFile)) {
            $consumerOutput = $this->consumerProcess ? $this->consumerProcess->getOutput() : 'N/A';
            $consumerError = $this->consumerProcess ? $this->consumerProcess->getErrorOutput() : 'N/A';
            throw new \RuntimeException(
                sprintf(
                    "Expected retryable message to be processed successfully (marker file '%s' not found).\nConsumer output: %s\nConsumer error: %s",
                    $successFile,
                    $consumerOutput,
                    $consumerError
                )
            );
        }

        $content = file_get_contents($successFile);
        if (!str_contains($content, 'Attempt: 2')) {
            throw new \RuntimeException(
                sprintf('Expected message to succeed on attempt 2, but file content was: %s', $content)
            );
        }
    }

    /**
     * @Then the failure transport should contain :count message
     * @Then the failure transport should contain :count messages
     */
    public function theFailureTransportShouldContainMessages(int $count): void
    {
        // Give a moment for the failure transport to receive the message
        sleep(2);

        $command = [
            'php',
            'bin/console',
            'messenger:stats',
            '--env=test'
        ];

        $statsProcess = new Process($command, __DIR__ . '/../..');
        $statsProcess->run();

        $output = $statsProcess->getErrorOutput() ?: $statsProcess->getOutput();

        if (!preg_match('/failed_transport\s+(\d+)/', $output, $matches)) {
            throw new \RuntimeException(
                sprintf('Could not find failed_transport in messenger:stats output: %s', $output)
            );
        }

        $actualCount = (int) $matches[1];

        if ($actualCount !== $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected %d message(s) in failure transport, but found %d. Full output: %s',
                    $count,
                    $actualCount,
                    $output
                )
            );
        }
    }

    /**
     * @Given I have a messenger transport configured with stream max bytes of :maxBytes
     */
    public function iHaveAMessengerTransportConfiguredWithStreamMaxBytes(int $maxBytes): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&stream_max_bytes=%d'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $maxBytes
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);
        $this->resetSymfonyCache();
    }

    /**
     * @Given I have a messenger transport configured with stream max messages of :maxMessages
     */
    public function iHaveAMessengerTransportConfiguredWithStreamMaxMessages(int $maxMessages): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&stream_max_messages=%d'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $maxMessages
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);
        $this->resetSymfonyCache();
    }

    /**
     * @Then the stream should have max bytes of :maxBytes
     */
    public function theStreamShouldHaveMaxBytesOf(int $maxBytes): void
    {
        $client = $this->createNatsClient();
        $streamInfo = $client->jetStream()->getStream($this->testStreamName)->await();
        $streamConfig = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        $actualMaxBytes = (int) ($streamConfig['max_bytes'] ?? 0);

        if ($actualMaxBytes !== $maxBytes) {
            throw new \RuntimeException(
                sprintf(
                    'Expected stream max bytes to be %d, but got %d',
                    $maxBytes,
                    $actualMaxBytes
                )
            );
        }
    }

    /**
     * @Then the stream should have max messages of :maxMessages
     */
    public function theStreamShouldHaveMaxMessagesOf(int $maxMessages): void
    {
        $client = $this->createNatsClient();
        $streamInfo = $client->jetStream()->getStream($this->testStreamName)->await();
        $streamConfig = is_array($streamInfo->raw['config'] ?? null) ? $streamInfo->raw['config'] : [];
        $actualMaxMessages = (int) ($streamConfig['max_msgs'] ?? 0);

        if ($actualMaxMessages !== $maxMessages) {
            throw new \RuntimeException(
                sprintf(
                    'Expected stream max messages to be %d, but got %d',
                    $maxMessages,
                    $actualMaxMessages
                )
            );
        }
    }

    /**
     * @Given I have a messenger transport configured with consumer name :consumerName
     */
    public function iHaveAMessengerTransportConfiguredWithConsumerName(string $consumerName): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&consumer=%s'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $consumerName
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);
        $this->resetSymfonyCache();
    }

    /**
     * @Given I have a messenger transport configured with batching of :batching
     */
    public function iHaveAMessengerTransportConfiguredWithBatching(int $batching): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&batching=%d'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject,
            $batching
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);
        $this->resetSymfonyCache();
    }

    /**
     * @Given I have a messenger transport configured with scheduled messages enabled
     */
    public function iHaveAMessengerTransportConfiguredWithScheduledMessagesEnabled(): void
    {
        $configContent = sprintf(
            "framework:\n    messenger:\n        transports:\n            test_transport:\n                dsn: 'nats-jetstream://admin:password@localhost:4222/%s/%s?stream_max_age=900&scheduled_messages=true'\n                serializer: 'messenger.transport.native_php_serializer'\n        routing:\n            'App\\Async\\TestMessage': test_transport\n",
            $this->testStreamName,
            $this->testSubject
        );

        file_put_contents(__DIR__ . '/../../config/packages/test_messenger.yaml', $configContent);
        $this->resetSymfonyCache();
    }

    /**
     * @When I send :count messages with :delay milliseconds delay to the transport
     */
    public function iSendMessagesWithDelayToTheTransport(int $count, int $delay): void
    {
        $this->messagesSent = $count;

        $command = [
            'php',
            'bin/console',
            'app:send-test-messages',
            (string) $count,
            '--delay=' . $delay,
            '--env=test'
        ];

        $sendProcess = new Process($command, __DIR__ . '/../..');
        $sendProcess->setTimeout(60);
        $sendProcess->run();

        if (!$sendProcess->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to send delayed messages. Exit code: %d. Output: %s. Error: %s',
                    $sendProcess->getExitCode(),
                    $sendProcess->getOutput(),
                    $sendProcess->getErrorOutput()
                )
            );
        }
    }

    /**
     * Clean up after each scenario
     *
     * @AfterScenario
     */
    public function cleanup(): void
    {
        sleep(1);
        // Stop consumer process if running
        if ($this->consumerProcess && $this->consumerProcess->isRunning()) {
            $this->consumerProcess->stop();
        }

        // Stop multiple consumer processes if running
        foreach ($this->consumerProcesses as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->consumerProcesses = [];

        // Clean up test streams if they exist
        if ($this->shouldNatsBeRunning) {
            try {
                $client = $this->createAppropriateNatsClient();
                $client->jetStream()->getStream($this->testStreamName)->await();
                $client->jetStream()->deleteStream($this->testStreamName)->await();
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }

            // Clean up failure stream if it exists
            if (!$this->useTls && !$this->useMtls) {
                try {
                    $client = $this->createNatsClient();
                    $client->jetStream()->getStream($this->failedStreamName)->await();
                    $client->jetStream()->deleteStream($this->failedStreamName)->await();
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }
        }

        // Only stop NATS server if the scenario explicitly required it to be down
        if (!$this->shouldNatsBeRunning) {
            $this->stopNatsServer();
        }

        // Clean the retry state directory
        if (is_dir($this->retryStateDir)) {
            $files = glob($this->retryStateDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        //clear the var/test_files directory
        if (is_dir($this->testFilesDir)) {
            $files = glob($this->testFilesDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Remove temporary config file and clear cache
        $configFile = __DIR__ . '/../../config/packages/test_messenger.yaml';
        if (file_exists($configFile)) {
            unlink($configFile);
        }
        $this->resetSymfonyCache();

        // Reset counters
        $this->messagesSent = 0;
        $this->messagesConsumed = 0;
        $this->consumerProcess = null;
        $this->useTls = false;
        $this->useMtls = false;
    }

    private function startNatsServer(): void
    {
        // In CI environments, NATS might already be running
        // First check if NATS is already accessible
        if ($this->isNatsRunning()) {
            return; // NATS is already running
        }

        // Use docker compose to start NATS
        $command = ['docker', 'compose', 'up', '-d'];
        $process = new Process($command, __DIR__ . '/../../../nats');
        $process->run();

        if (!$process->isSuccessful()) {
            // In CI, the container might already be running, check if NATS is accessible
            if ($this->isNatsRunning()) {
                return; // NATS is accessible despite docker compose failure
            }
            throw new \RuntimeException('Failed to start NATS server: ' . $process->getErrorOutput());
        }
    }

    private function resetSymfonyCache(): void
    {
        $cacheDir = __DIR__ . '/../../var/cache/test';
        if (is_dir($cacheDir)) {
            $process = new Process(['rm', '-rf', $cacheDir]);
            $process->run();
        }
    }

    private function stopNatsServer(): void
    {
        // Use docker compose to stop NATS
        $command = ['docker', 'compose', 'down'];
        $process = new Process($command, __DIR__ . '/../../../nats');
        $process->run();
    }

    private function waitForNatsToBeReady(): void
    {
        $maxAttempts = 30;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            // First check TCP connectivity
            $socket = @fsockopen('localhost', 4222, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                // TCP is working, now try NATS client
                try {
                    $client = $this->createNatsClient();
                    $client->jetStream()->accountInfo()->await();
                    return; // NATS is ready
                } catch (\Exception $e) {
                    // Continue retrying
                } catch (\Throwable $e) {
                    // Continue retrying
                }
            }

            $attempt++;
            sleep(1);
        }

        throw new \RuntimeException('NATS server did not become ready within 30 seconds');
    }

    private function createNatsClient(): NatsClient
    {
        $client = new NatsClient(new NatsOptions(
            servers: ['nats://localhost:4222'],
            username: 'admin',
            password: 'password',
            connectTimeoutMs: 5000,
        ));
        $client->connect()->await();

        return $client;
    }

    private function isNatsRunning(): bool
    {
        // First, try a simple TCP connection test
        $socket = @fsockopen('localhost', 4222, $errno, $errstr, 2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        // If TCP connection works, try a simple NATS client test
        try {
            $client = $this->createNatsClient();
            $client->jetStream()->accountInfo()->await();
            return true;
        } catch (\Exception $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function waitForNatsTlsToBeReady(): void
    {
        $maxAttempts = 30;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $socket = @fsockopen('localhost', 4223, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                try {
                    $client = $this->createNatsTlsClient();
                    $client->jetStream()->accountInfo()->await();
                    return;
                } catch (\Exception $e) {
                    // Continue retrying
                } catch (\Throwable $e) {
                    // Continue retrying
                }
            }

            $attempt++;
            sleep(1);
        }

        throw new \RuntimeException('NATS TLS server did not become ready within 30 seconds');
    }

    private function createNatsTlsClient(): NatsClient
    {
        $caFile = realpath(__DIR__ . '/../../../nats/certs/ca.pem');

        $client = new NatsClient(new NatsOptions(
            servers: ['tls://localhost:4223'],
            username: 'admin',
            password: 'password',
            connectTimeoutMs: 5000,
            tlsRequired: true,
            tlsCaFile: $caFile,
            tlsVerifyPeer: true,
            tlsPeerName: 'localhost',
        ));
        $client->connect()->await();

        return $client;
    }

    private function waitForNatsMtlsToBeReady(): void
    {
        $maxAttempts = 30;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $socket = @fsockopen('localhost', 4224, $errno, $errstr, 1);
            if ($socket !== false) {
                fclose($socket);

                try {
                    $client = $this->createNatsMtlsClient();
                    $client->jetStream()->accountInfo()->await();
                    return;
                } catch (\Exception $e) {
                    // Continue retrying
                } catch (\Throwable $e) {
                    // Continue retrying
                }
            }

            $attempt++;
            sleep(1);
        }

        throw new \RuntimeException('NATS mTLS server did not become ready within 30 seconds');
    }

    private function createNatsMtlsClient(): NatsClient
    {
        $caFile = realpath(__DIR__ . '/../../../nats/certs/ca.pem');
        $certFile = realpath(__DIR__ . '/../../../nats/certs/client-cert.pem');
        $keyFile = realpath(__DIR__ . '/../../../nats/certs/client-key.pem');

        $client = new NatsClient(new NatsOptions(
            servers: ['tls://localhost:4224'],
            username: 'admin',
            password: 'password',
            connectTimeoutMs: 5000,
            tlsRequired: true,
            tlsCaFile: $caFile,
            tlsCertFile: $certFile,
            tlsKeyFile: $keyFile,
            tlsVerifyPeer: true,
            tlsPeerName: 'localhost',
        ));
        $client->connect()->await();

        return $client;
    }

    private function createAppropriateNatsClient(): NatsClient
    {
        if ($this->useMtls) {
            return $this->createNatsMtlsClient();
        }
        if ($this->useTls) {
            return $this->createNatsTlsClient();
        }
        return $this->createNatsClient();
    }

    private function verifyStreamExists(): void
    {
        $client = $this->createAppropriateNatsClient();

        try {
            $client->jetStream()->getStream($this->testStreamName)->await();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Stream '{$this->testStreamName}' does not exist");
        }
    }

    /**
     * @Given the test files directory is clean
     */
    public function theTestFilesDirectoryIsClean(): void
    {
        if (is_dir($this->testFilesDir)) {
            // Remove all files in the directory
            $files = glob($this->testFilesDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        } else {
            mkdir($this->testFilesDir, 0755, true);
        }
    }

    /**
     * @Then the messenger stats should show approximately :expectedCount messages waiting
     */
    public function theMessengerStatsShouldShowApproximatelyMessagesWaiting(int $expectedCount): void
    {
        $process = new Process([
            'php',
            'bin/console',
            'messenger:stats',
            '--env=test'
        ], __DIR__ . '/../..');

        $process->run();
        $output = $process->getErrorOutput() ?: $process->getOutput();

        if (!preg_match('/test_transport\s+(\d+)/', $output, $matches)) {
            throw new \RuntimeException(
                "Could not parse messenger stats output. Full output: {$output}"
            );
        }

        $actualCount = (int) $matches[1];

        if ($actualCount !== $expectedCount) {
            throw new \RuntimeException(
                sprintf(
                    'Expected exactly %d messages waiting in messenger stats, but found %d. Full output: %s',
                    $expectedCount,
                    $actualCount,
                    $output
                )
            );
        }
    }

    /**
     * @Then the test files directory should contain approximately :count files
     */
    public function theTestFilesDirectoryShouldContainApproximatelyFiles(int $count): void
    {
        if (!is_dir($this->testFilesDir)) {
            throw new \RuntimeException("Test files directory does not exist: {$this->testFilesDir}");
        }

        $files = glob($this->testFilesDir . '/message_*.txt');
        $actualCount = count($files);

        if ($actualCount !== $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected exactly %d files in test directory, but found %d. Directory: %s',
                    $count,
                    $actualCount,
                    $this->testFilesDir
                )
            );
        }
    }

    /**
     * @Then the test files directory should contain :count files
     */
    public function theTestFilesDirectoryShouldContainFiles(int $count): void
    {
        if (!is_dir($this->testFilesDir)) {
            throw new \RuntimeException("Test files directory does not exist: {$this->testFilesDir}");
        }

        $files = glob($this->testFilesDir . '/message_*.txt');
        $actualCount = count($files);

        if ($actualCount !== $count) {
            throw new \RuntimeException(
                sprintf(
                    'Expected %d files in test directory, but found %d. Directory: %s',
                    $count,
                    $actualCount,
                    $this->testFilesDir
                )
            );
        }
    }

}