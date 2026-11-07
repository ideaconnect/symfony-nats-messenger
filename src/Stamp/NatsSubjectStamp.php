<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * The subject a message is published to, in place of the transport's default subject (the topic in the DSN).
 *
 * The topic in the DSN is the default publish subject. An application adds this stamp to route one message to a
 * subject of its own, such as `orders.updated.42` for order 42. For the transport's own consumer to read it, the
 * subject must be the topic or match one of the transport's `additional_subjects`, the patterns its stream and
 * durable consumer cover next to the topic.
 *
 * The stamp is not sent with the message. A retry of a message the transport received goes to the subject it
 * arrived on ({@see NatsReceivedSubjectStamp}), and a copy sent to a failure transport goes to that transport's own
 * topic. A delayed message waits under the topic (`{topic}.delayed.{uuid}`) and is delivered to this subject.
 */
final class NatsSubjectStamp implements NonSendableStampInterface
{
    /**
     * @throws InvalidArgumentException If the subject is not one a message can be published to: it is empty,
     *                                  contains whitespace or an empty token, or has a `*` or `>` token, the
     *                                  wildcards a subscription may use but a publish subject cannot.
     */
    public function __construct(public readonly string $subject)
    {
        if ($subject === '' || preg_match('/\s/', $subject) === 1) {
            throw new InvalidArgumentException(sprintf(
                'A NATS subject must be a non-empty string without whitespace, "%s" is not.',
                $subject,
            ));
        }

        foreach (explode('.', $subject) as $token) {
            if ($token === '' || $token === '*' || $token === '>') {
                throw new InvalidArgumentException(sprintf(
                    'A message cannot be published to "%s": a subject is dot-separated tokens, none empty and none the wildcards "*" or ">".',
                    $subject,
                ));
            }
        }
    }
}
