<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The id JetStream deduplicates a message by.
 *
 * With the `deduplicate` option the transport adds one on the first send, and since the stamp is sent with
 * the message, a copy of it that is sent again keeps the id: Symfony's retry of a message NATS redelivered,
 * or the send to a failure transport. An application can add its own to cover a message it dispatches again
 * itself, for example after a send() that timed out, since a new envelope would otherwise get a new id. An
 * id the application adds is used even with the option off.
 *
 * The transport publishes it in the Nats-Msg-Id header, followed by Symfony's retry count, so that each
 * retry is a message of its own, and by a marker on the copy sent to a failure transport. JetStream drops a
 * message whose Nats-Msg-Id it stored within the stream's duplicate window (`stream_duplicate_window`).
 */
final class DeduplicationIdStamp implements StampInterface
{
    /**
     * @throws InvalidArgumentException If the id is empty or contains a line break, which a header cannot carry
     */
    public function __construct(public readonly string $id)
    {
        if (trim($id) === '' || strpbrk($id, "\r\n") !== false) {
            throw new InvalidArgumentException('A deduplication id must be a non-empty string without line breaks.');
        }
    }
}
