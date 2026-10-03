<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function Amp\delay;

/**
 * Works on a message for as many seconds as it asks for. It waits on the event loop in short steps, as a
 * handler doing asynchronous I/O does, so a keepalive Symfony sends from its signal handler goes out while
 * the handler is still running.
 */
#[AsMessageHandler]
class SlowMessageHandler
{
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

    public function __invoke(SlowMessage $message): void
    {
        // Record every delivery, so tests can tell whether NATS redelivered the message to another consumer
        // while the first one was still working on it.
        $attemptFile = sprintf('%s/slow_message_%d.attempt', $this->retryStateDir, $message->messageId);
        $attempt = file_exists($attemptFile) ? (int) file_get_contents($attemptFile) : 0;
        $attempt++;
        file_put_contents($attemptFile, (string) $attempt);

        echo "[WORKING] Slow message {$message->messageId} attempt {$attempt} - {$message->seconds} seconds\n";

        $end = microtime(true) + $message->seconds;
        while (microtime(true) < $end) {
            delay(0.1);
        }

        $doneFile = sprintf('%s/slow_message_%d.txt', $this->testFilesDir, $message->messageId);
        file_put_contents($doneFile, sprintf(
            "Message ID: %d\nAttempt: %d\nProcessed at: %s\n",
            $message->messageId,
            $attempt,
            date('Y-m-d H:i:s')
        ));

        echo "[OK] Slow message {$message->messageId} handled on attempt {$attempt}\n";
    }
}
