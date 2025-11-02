<?php

namespace App\Async;

class TestMessage
{
    public string $content = '';
    public int $timestamp = 0;
    public int $messageId = 0;

    public function __construct(string $content = '', int $messageId = 0)
    {
        $this->content = $content;
        $this->messageId = $messageId;
        $this->timestamp = time();
    }
}