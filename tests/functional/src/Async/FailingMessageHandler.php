<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FailingMessageHandler
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

    public function __invoke(FailingMessage $message): void
    {
        if (!$message->shouldEventuallySucceed) {
            echo "[FAIL] Message {$message->messageId} always fails\n";
            throw new \RuntimeException("Message {$message->messageId} is configured to always fail.");
        }

        $attemptFile = sprintf('%s/message_%d.attempt', $this->retryStateDir, $message->messageId);
        $attempt = file_exists($attemptFile) ? (int) file_get_contents($attemptFile) : 0;
        $attempt++;
        file_put_contents($attemptFile, (string) $attempt);

        if ($attempt < 2) {
            echo "[FAIL] Message {$message->messageId} attempt {$attempt} - failing for retry\n";
            throw new \RuntimeException("Message {$message->messageId} intentionally fails on attempt {$attempt}.");
        }

        // Success on second attempt
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
