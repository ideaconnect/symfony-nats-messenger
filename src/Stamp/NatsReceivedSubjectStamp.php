<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * The subject a message arrived on, which get() adds to every envelope it yields.
 *
 * The retry Symfony sends of a failed message goes back to this subject, so a message published with a
 * {@see NatsSubjectStamp} keeps its subject across retries.
 */
final class NatsReceivedSubjectStamp implements NonSendableStampInterface
{
    public function __construct(public readonly string $subject)
    {
    }
}
