<?php

namespace App\Async;

class SlowMessage
{
    public int $messageId = 0;
    public int $seconds = 0;

    public function __construct(int $messageId = 0, int $seconds = 0)
    {
        $this->messageId = $messageId;
        $this->seconds = $seconds;
    }
}
