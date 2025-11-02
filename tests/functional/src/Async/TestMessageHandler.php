<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TestMessageHandler
{
    private string $testDir;

    public function __construct()
    {
        $this->testDir = __DIR__ . '/../../var/test_files';
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    public function __invoke(TestMessage $message): void
    {
        // Save a file with the message ID as filename
        $filename = sprintf('%s/message_%d.txt', $this->testDir, $message->messageId);
        if (file_exists($filename)) {
            // Avoid overwriting existing files for idempotency
            throw new \RuntimeException("Message file {$filename} already exists.");
        }

        file_put_contents($filename, sprintf(
            "Message ID: %d\nContent: %s\nTimestamp: %d\nProcessed at: %s\n",
            $message->messageId,
            $message->content,
            $message->timestamp,
            date('Y-m-d H:i:s')
        ));

        // Log that we processed a message for testing purposes
        echo "[OK] Consumed message {$message->messageId}: {$message->content}\n";
    }
}