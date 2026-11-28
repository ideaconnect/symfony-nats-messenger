<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
class FailingMessageHandler
{
    /** How long a run held for the test waits for its release before it fails anyway. */
    private const HOLD_SECONDS = 60;

    private string $retryStateDir;
    private string $testFilesDir;

    public function __construct()
    {
        $this->retryStateDir = __DIR__ . '/../../var/retry_state';
        $this->testFilesDir = __DIR__ . '/../../var/test_files';

        if (!is_dir($this->retryStateDir)) {
            mkdir($this->retryStateDir, 0755, true);
        }
        if (!is_dir($this->testFilesDir)) {
            mkdir($this->testFilesDir, 0755, true);
        }
    }

    public function __invoke(FailingMessage $message): void
    {
        // Record the delivery attempt for every message (including always-failing ones) so tests can
        // assert how many times NATS redelivered - e.g. that max_deliver bounds a poison message.
        $attemptFile = sprintf('%s/message_%d.attempt', $this->retryStateDir, $message->messageId);
        $attempt = file_exists($attemptFile) ? (int) file_get_contents($attemptFile) : 0;
        $attempt++;
        file_put_contents($attemptFile, (string) $attempt);
        // And when each run started, one line per run, so tests can assert how long each retry waited.
        file_put_contents(sprintf('%s/message_%d.runs', $this->retryStateDir, $message->messageId), sprintf("%.6F\n", microtime(true)), FILE_APPEND);

        if ($message->crashesTheWorkerOnItsFirstRun && $attempt === 1) {
            // As a worker that crashes, or is killed, while it handles a message: the delivery is never settled.
            echo "[CRASH] Message {$message->messageId} attempt {$attempt} - the worker exits without settling it\n";
            exit(70);
        }

        if ($message->heldOnItsFirstRun && $attempt === 1) {
            // Held until the test releases it, without running the event loop, as a handler doing blocking work: the
            // client reads nothing from its socket meanwhile.
            echo "[HOLD] Message {$message->messageId} attempt {$attempt} - held until released\n";
            $releaseFile = sprintf('%s/message_%d.release', $this->retryStateDir, $message->messageId);
            touch(sprintf('%s/message_%d.held', $this->retryStateDir, $message->messageId));
            $deadline = microtime(true) + self::HOLD_SECONDS;
            while (!file_exists($releaseFile) && microtime(true) < $deadline) {
                usleep(50000);
            }

            echo "[FAIL] Message {$message->messageId} attempt {$attempt} - failing after its release\n";
            throw new \RuntimeException("Message {$message->messageId} fails on attempt {$attempt}, after its release.");
        }

        if ($message->unrecoverable) {
            echo "[FAIL] Message {$message->messageId} attempt {$attempt} - unrecoverable\n";
            throw new UnrecoverableMessageHandlingException("Message {$message->messageId} can never be handled.");
        }

        if ($message->recoverableFailures > 0) {
            if ($attempt <= $message->recoverableFailures) {
                echo "[FAIL] Message {$message->messageId} attempt {$attempt} - recoverable\n";
                throw new RecoverableMessageHandlingException("Message {$message->messageId} fails recoverably on attempt {$attempt}.");
            }
        } elseif (!$message->shouldEventuallySucceed) {
            echo "[FAIL] Message {$message->messageId} attempt {$attempt} - always fails\n";
            throw new \RuntimeException("Message {$message->messageId} is configured to always fail.");
        } elseif ($attempt < 2) {
            echo "[FAIL] Message {$message->messageId} attempt {$attempt} - failing for retry\n";
            throw new \RuntimeException("Message {$message->messageId} intentionally fails on attempt {$attempt}.");
        }

        // Success on the first attempt that does not fail
        $successFile = sprintf('%s/failing_message_%d.txt', $this->testFilesDir, $message->messageId);
        file_put_contents($successFile, sprintf(
            "Message ID: %d\nContent: %s\nAttempt: %d\nProcessed at: %s\n",
            $message->messageId,
            $message->content,
            $attempt,
            date('Y-m-d H:i:s')
        ));

        echo "[OK] Message {$message->messageId} succeeded on attempt {$attempt}\n";
    }
}
