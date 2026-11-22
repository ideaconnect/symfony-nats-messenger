<?php

namespace App\Async;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** The handler of {@see TwoHandlersMessage} that runs second, after {@see FirstOfTwoHandlers}. */
#[AsMessageHandler(priority: 0)]
class SecondOfTwoHandlers extends AbstractTwoHandlersMessageHandler
{
    public function __invoke(TwoHandlersMessage $message): void
    {
        $this->handle('second', $message->second);
    }
}
