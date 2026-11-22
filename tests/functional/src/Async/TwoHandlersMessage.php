<?php

namespace App\Async;

/**
 * A message with two handlers, {@see FirstOfTwoHandlers} and {@see SecondOfTwoHandlers}, in that order. Each fails as
 * the message says: `for good` with an UnrecoverableMessageHandlingException on every run, `once` with a
 * RuntimeException on its first run only, after which it succeeds.
 */
class TwoHandlersMessage
{
    public function __construct(
        public string $first = 'for good',
        public string $second = 'once',
    ) {
    }
}
