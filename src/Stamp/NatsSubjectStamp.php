<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Stamp;

use InvalidArgumentException;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * The subject a message is published to, in place of the transport's default subject (the topic in the DSN).
 *
 * The topic in the DSN is the default publish subject. An application adds this stamp to route one message to a
 * subject of its own, such as `orders.updated.42` for order 42. A transport uses the stamp only when its own
 * consumer reads the subject: the subject is the topic or matches one of the transport's `additional_subjects`, the
 * patterns its stream and durable consumer cover next to the topic. Otherwise it publishes to its topic.
 *
 * The stamp is not sent with the message. A retry of a message the transport received goes to the subject it
 * arrived on ({@see NatsReceivedSubjectStamp}), and a copy sent to a failure transport goes to that transport's own
 * topic. A delayed message waits under the topic (`{topic}.delayed.{uuid}`) and is delivered to this subject.
 */
final class NatsSubjectStamp implements NonSendableStampInterface
{
    /**
     * @throws InvalidArgumentException If the subject is not one a message can be published to: it is empty,
     *                                  contains whitespace or an empty token, or has a `*` or `>` in a token, the
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
            if ($token === '' || str_contains($token, '*') || str_contains($token, '>')) {
                throw new InvalidArgumentException(sprintf(
                    'A message cannot be published to "%s": a subject is dot-separated tokens, none empty and none with the wildcards "*" or ">" in it.',
                    $subject,
                ));
            }
        }
    }
}
