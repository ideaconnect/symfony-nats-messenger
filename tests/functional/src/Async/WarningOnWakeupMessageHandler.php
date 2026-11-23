<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts each run for a message in `var/test_files/warning_on_wakeup_<id>.txt`.
 */
#[AsMessageHandler]
class WarningOnWakeupMessageHandler
{
    private string $testFilesDir;

    public function __construct()
    {
        $this->testFilesDir = __DIR__ . '/../../var/test_files';
        if (!is_dir($this->testFilesDir)) {
            mkdir($this->testFilesDir, 0755, true);
        }
    }

    public function __invoke(WarningOnWakeupMessage $message): void
    {
        $file = sprintf('%s/warning_on_wakeup_%d.txt', $this->testFilesDir, $message->messageId);
        $runs = file_exists($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($runs + 1));

        echo "[OK] Handled message {$message->messageId} whose __wakeup() raised a warning\n";
    }
}
