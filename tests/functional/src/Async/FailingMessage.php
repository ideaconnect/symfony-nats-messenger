<?php

namespace App\Async;

class FailingMessage
{
    public int $messageId = 0;
    public string $content = '';
    public int $timestamp = 0;
    public bool $shouldEventuallySucceed = false;
    public bool $unrecoverable = false;
    /** The handler throws a RecoverableMessageHandlingException on this many runs, then succeeds. */
    public int $recoverableFailures = 0;
    /** The handler ends the worker's process on the first run, so that nobody settles that delivery. */
    public bool $crashesTheWorkerOnItsFirstRun = false;
    /** The handler holds its first run until the test releases it, then fails it. */
    public bool $heldOnItsFirstRun = false;

    public function __construct(
        string $content = '',
        int $messageId = 0,
        bool $shouldEventuallySucceed = false,
        bool $unrecoverable = false,
        int $recoverableFailures = 0,
        bool $crashesTheWorkerOnItsFirstRun = false,
        bool $heldOnItsFirstRun = false,
    ) {
        $this->content = $content;
        $this->messageId = $messageId;
        $this->timestamp = time();
        $this->shouldEventuallySucceed = $shouldEventuallySucceed;
        $this->unrecoverable = $unrecoverable;
        $this->recoverableFailures = $recoverableFailures;
        $this->crashesTheWorkerOnItsFirstRun = $crashesTheWorkerOnItsFirstRun;
        $this->heldOnItsFirstRun = $heldOnItsFirstRun;
    }
}
