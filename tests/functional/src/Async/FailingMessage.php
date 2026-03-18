<?php

namespace App\Async;

class FailingMessage
{
    public int $messageId = 0;
    public string $content = '';
    public int $timestamp = 0;
    public bool $shouldEventuallySucceed = false;

    public function __construct(string $content = '', int $messageId = 0, bool $shouldEventuallySucceed = false)
    {
        $this->content = $content;
        $this->messageId = $messageId;
        $this->timestamp = time();
        $this->shouldEventuallySucceed = $shouldEventuallySucceed;
    }
}
