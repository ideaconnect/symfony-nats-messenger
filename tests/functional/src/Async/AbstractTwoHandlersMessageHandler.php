<?php

namespace App\Async;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * One of the two handlers of {@see TwoHandlersMessage}. Each run is counted in `var/retry_state/two_handlers_<position>.attempt`,
 * and a run that succeeds writes `var/test_files/two_handlers_<position>.txt` with the number of that run.
 */
abstract class AbstractTwoHandlersMessageHandler
{
    private string $retryStateDir;
    private string $testFilesDir;

    public function __construct()
    {
        $this->retryStateDir = __DIR__ . '/../../var/retry_state';
        $this->testFilesDir = __DIR__ . '/../../var/test_files';

        foreach ([$this->retryStateDir, $this->testFilesDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * @param string $position 'first' or 'second'
     * @param string $failing  'for good' or 'once'
     */
    protected function handle(string $position, string $failing): void
    {
        $attemptFile = sprintf('%s/two_handlers_%s.attempt', $this->retryStateDir, $position);
        $attempt = (file_exists($attemptFile) ? (int) file_get_contents($attemptFile) : 0) + 1;
        file_put_contents($attemptFile, (string) $attempt);

        if ($failing === 'for good') {
            echo "[FAIL] The {$position} handler, run {$attempt}: for good\n";
            throw new UnrecoverableMessageHandlingException("The {$position} handler can never handle this message.");
        }

        if ($attempt === 1) {
            echo "[FAIL] The {$position} handler, run {$attempt}: once\n";
            throw new \RuntimeException("The {$position} handler fails on its first run.");
        }

        file_put_contents(sprintf('%s/two_handlers_%s.txt', $this->testFilesDir, $position), "Attempt: {$attempt}\n");
        echo "[OK] The {$position} handler succeeded on run {$attempt}\n";
    }
}
