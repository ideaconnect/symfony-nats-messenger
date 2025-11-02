<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        // Log that we processed a message for testing purposes
        echo "[OK] Consumed message {$message->messageId}: {$message->content}\n";
    }
}