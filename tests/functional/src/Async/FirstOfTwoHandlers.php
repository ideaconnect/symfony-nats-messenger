<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The handler of {@see TwoHandlersMessage} that runs first: the higher priority runs first. */
#[AsMessageHandler(priority: 10)]
class FirstOfTwoHandlers extends AbstractTwoHandlersMessageHandler
{
    public function __invoke(TwoHandlersMessage $message): void
    {
        $this->handle('first', $message->first);
    }
}
