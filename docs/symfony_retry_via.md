# Symfony's retries carried out by NATS (`retry_via`)

The reference for `retry_via`, the option that chooses how a retry decided by Symfony's retry strategy reaches
NATS: as a copy of the message (`copy`, the default) or as a NAK of the failed delivery with Symfony's delay
(`nak`). Added in 5.9.0, from PR #113 by Petar Fedorovsky.

The README section [Retrying through a NAK](../README.md#retrying-through-a-nak-retry_via-nak) says when to use
it and what to watch. This document holds what stands behind it: the terms, the three retry setups delivery by
delivery, what the NATS server does with a NAK, how Symfony's worker settles a retried message, and what was
measured. [PRs/PR-113-symfony-retry-via.md](PRs/PR-113-symfony-retry-via.md) records the decisions.

```yaml
framework:
  messenger:
    transports:
      async:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic?retry_via=nak'
        retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, max_delay: 60000 }
```

## Terms

| Term | Meaning |
|---|---|
| **symfony mode** | `retry_handler: symfony`, the default. Symfony's retry strategy decides whether and when a failed message is retried. `retry_via` belongs to this mode. |
| **nats mode** | `retry_handler: nats`. NATS redelivers every failed message itself, and Symfony's retry strategy has no effect. |
| **retry copy** | The envelope Symfony's retry listener sends to the transport when it retries a message: the same message, with a `DelayStamp` holding the delay the strategy computed and a `RedeliveryStamp` holding the retry count. |
| **NAK retry** | A retry carried out by NAKing the failed delivery with the retry copy's delay. NATS then redelivers the original message, and no copy is published. |
| **delivery token** | The reply subject of one delivery (`$JS.ACK.<stream>.<consumer>.<deliveries>.<stream sequence>...`), which the transport keeps in the envelope's `TransportMessageIdStamp`. An ACK, a NAK or a TERM is a message published to it. It names one delivery, not the message, and says how many times the message has been delivered. |
| **settle** | To end a delivery with an ACK, a NAK or a TERM. The worker settles each delivery once, through the transport's `ack()` or `reject()`. |
| **give up** | Symfony decides not to retry a failed message (`willRetry()` is false): its retries are used up, the failure is unrecoverable, or the transport has no retry strategy. Symfony then sends a copy to the failure transport, if one is configured. |
| **retry count** | The number in the envelope's `RedeliveryStamp`, which the retry strategy reads to compute the delay and to compare with `max_retries`. 0 on a first delivery. |
| **last delivery** | The last delivery the consumer's `max_deliver` allows. NATS redelivers nothing after it, whatever a NAK says. |

## The three setups, delivery by delivery

A handler that always fails, with `retry_strategy: { max_retries: 2, delay: 1000, multiplier: 2 }`. "The
transport sends" lists what goes to NATS: a publish to the subject, or a frame to the delivery token.

### `retry_via: copy` (the default)

| Run | What is delivered | Retry count | Symfony | The transport sends | NATS |
|---|---|---|---|---|---|
| 1 | the message, delivery 1 | 0 | retries in 1 s | a publish of the retry copy (count 1); then `+ACK` or `+TERM` for delivery 1 | stores the copy as a new message and delivers it at once |
| 2 | copy 1, delivery 1 | 1 | retries in 2 s | a publish of the retry copy (count 2); then `+ACK` or `+TERM` | stores the copy and delivers it at once |
| 3 | copy 2, delivery 1 | 2 | gives up | `+TERM` | publishes MSG_TERMINATED |

The stream ends with 3 messages, and every consumer on the subject has received all three. With
`scheduled_messages` (NATS 2.12 and later) each copy goes to `<topic>.delayed.<uuid>` with `Nats-Schedule`
headers instead, and NATS delivers it at the next whole second after the delay. Without it the delay is dropped.
`+ACK` or `+TERM` depends on the Symfony Messenger release (see
[How the worker settles a retried message](#how-the-worker-settles-a-retried-message)).

### `retry_via: nak`

| Run | What is delivered | Retry count | Symfony | The transport sends | NATS |
|---|---|---|---|---|---|
| 1 | the message, delivery 1 | 0 | retries in 1 s | `-NAK {"delay":1000000000}` | redelivers the message 1 s later |
| 2 | the message, delivery 2 | 1 | retries in 2 s | `-NAK {"delay":2000000000}` | redelivers the message 2 s later |
| 3 | the message, delivery 3 | 2 | gives up | `+TERM` | publishes MSG_TERMINATED |

The stream ends with the 1 message, and other consumers on the subject received it once. The delay in the frame
is in nanoseconds. The retry count is not in the message: the transport reads the deliveries from the delivery
token and adds the earlier ones to the count the message was published with.

### `retry_handler: nats`

With `nak_delay: 1`, `max_deliver: 3` and, as the README asks for in this mode, `max_retries: 0`.

| Run | What is delivered | Retry count | Symfony | The transport sends | NATS |
|---|---|---|---|---|---|
| 1 | the message, delivery 1 | 0 | gives up | `-NAK {"delay":1000000000}` | redelivers the message 1 s later |
| 2 | the message, delivery 2 | 0 | gives up | `-NAK {"delay":1000000000}` | redelivers the message 1 s later |
| 3 | the message, delivery 3 | 0 | gives up | `-NAK {"delay":1000000000}` | redelivers nothing, publishes MAX_DELIVERIES, and keeps the message, except where the list below says it deletes it |

Symfony gives up on every run, so a failure transport gets a copy each time. That is why a failure transport
does not combine well with nats mode, and why `retry_via: nak` is the setup to use when Symfony's strategy
should decide while NATS redelivers.

## What `retry_via: nak` changes in the transport

- **`send()`** recognises Symfony's retry copy: an envelope received in this process (`ReceivedStamp`) with a
  retry count above 0. When a NAK can carry the retry, the copy is not published. The transport remembers the
  delivery token and the copy's `DelayStamp` delay, by message object, in a `WeakMap`, so a message that is never
  settled leaves nothing behind.
- **A NAK cannot carry every retry.** A copy without its delivery token is published as with `copy`. So is the
  retry of the last delivery `max_deliver` allows: a NAK would end the message there, handled by nobody and in no
  failure transport. The copy is a new message with deliveries of its own, so Symfony's strategy runs to its end
  whatever `max_deliver` is.
- **The last delivery is the consumer's.** NATS applies the consumer's `max_deliver`, which is the option's only
  once `setup()` has written it. So the transport asks for the consumer's info at the first retry that needs it,
  and again once the answer is a minute old: one request a minute at most, only while deliveries fail. A limit
  that differs from the option is followed and logged once; a consumer it may not read leaves the option to decide,
  with one warning, and is not asked again on that connection. `setup()`'s own answer is not taken, since another
  configuration's setup can change the consumer right after. Up to 5.9.0 the option decided: with the option at 5,
  or unset, on a consumer limited to 3, and `max_retries` 4, the retry of the third delivery was NAKed, NATS
  redelivered nothing, and the message was gone after 3 runs, with no failure-transport copy and one MAX_DELIVERIES
  advisory (measured on nats-server 2.15.2). With 5.9.1 the same runs are 5, with 1 failure-transport copy.
- **`ack()` and `reject()`** NAK the remembered delivery with the delay. A delay of 0 is a plain NAK. A delay
  above 9,223,372,036,854 ms is cut there, since the frame carries nanoseconds in a 64-bit integer. The NAK goes
  out after a check of the connection, as the settling of any failed delivery does.
- **`get()`** sets the retry count of a redelivered message: the count it was published with, plus its
  deliveries before this one. A delivery nobody settled, after a crash or an `ack_wait` that ran out, counts.
- **A message Symfony gives up on** is TERMed by symfony mode's existing code, as with `copy`. So is a message
  the transport's serializer cannot decode, at once before Symfony Messenger 8.1 and after its retries from 8.1 on
  (see below).
- **The builder** refuses any `retry_via` with `retry_handler: nats`, and with `nak` a positive `nak_delay` and
  any `backoff`. Without `nak` both stay accepted.

## What the server does with a NAK

Read from the source of nats-server 2.15.0 (`processNak()` and `checkPending()` in `server/consumer.go`, and
`server/store.go`), and measured on nats-server 2.9.25, 2.10.29, 2.11.17, 2.15.1 and 2.15.2 in Docker.

- **A plain NAK is redelivered at once,** and never uses the consumer's `backoff`. PR #113 measured 0.00 s on a
  consumer with backoff 3 and 6 s.
- **A NAK with a delay is held for the delay.** The server sets the pending entry's time to `now - ack_wait +
  delay`, so the delivery is due `delay` from now and `ack_wait` does not shorten it. Until then the message
  counts as one pending acknowledgement, so it holds one of the consumer's `max_ack_pending` slots (1000 by
  default). Once they are all held, the consumer gets no new messages.
- **The hold survives a restart of the server,** which stores the time of a pending delivery in whole seconds
  ("Downsample to seconds" in `store.go`). A 12 s NAK with `ack_wait` 2 s, the server restarted 1 s later, was
  redelivered once, after 11.85 s on 2.15.2 and 11.48 s on 2.10.29: up to a second early, never twice. PR #113
  held a 2 hour delay across a restart for the 180 s it watched.
- **`backoff` lengthens a NAK with a delay** by `backoff[n] - backoff[0]`, where n counts the earlier deliveries,
  and replaces `ack_wait` with `backoff[0]`. A 0.5 s NAK on a consumer with backoff 1, 2 and 3 s was redelivered
  after 0.50, 1.50 and 2.50 s (2.10.29 and 2.15.1). A setup without `backoff` takes a consumer's backoff out
  again and puts `ack_wait` back to its default, since the transport sends the consumer's whole configuration.
- **The delay has no upper limit on the server.** It is a Go `time.Duration`, a 64-bit count of nanoseconds. The
  longest delay the transport sends, 2^63 ns or about 292 years, is held and not wrapped around.
- **A message can leave the stream while its retry waits.** A 4 s NAK on a stream with `stream_max_age` 2 s: the
  message was gone before its retry, and nothing was redelivered.
- **A TERM is an ACK with an advisory.** The server publishes
  `$JS.EVENT.ADVISORY.CONSUMER.MSG_TERMINATED.<stream>.<consumer>` and does not redeliver. The message stays in a
  stream with limits retention, and leaves a work-queue or interest stream as an acknowledged message does.
- **After the last delivery `max_deliver` allows, NATS redelivers nothing** and publishes
  `$JS.EVENT.ADVISORY.CONSUMER.MAX_DELIVERIES.<stream>.<consumer>`: at the next pull after a plain NAK, and when
  the delay has passed after a NAK with a delay. This is why the retry of that delivery goes out as a copy.
- **A message stopped at `max_deliver` does not always stay in the stream.** NATS means to keep it (its docs: "Messages
  that have reached the maximum delivery count will stay in the stream"), and keeps it on a stream with limits
  retention. On the other two it depends on the retention, the replicas and the server, measured in November 2026
  with a message NAKed to `max_deliver` and later messages acknowledged:

  | Stream | Kept | Deleted |
  |---|---|---|
  | limits | always | never |
  | work-queue, one server or one replica | on every version tested (2.10.29 to 2.15.2), across a restart too | - |
  | work-queue, three replicas | from 2.12.5 (2.12.5 to 2.12.15, 2.15.2), across a leader change and a rolling restart | before 2.12.5 (2.10.29, 2.11.17, 2.12.0, 2.12.4) |
  | interest, a standalone server | until a restart; across it from 2.12.9 and 2.14.1 | at a restart before 2.12.9 (2.10.29, 2.11.17, 2.12.5, 2.12.8) and on 2.14.0 |
  | interest, inside a cluster | - | with three replicas on every version tested (2.10.29 to 2.15.2), 2 to 8 s after a later message was acknowledged on a fresh stream and about two minutes later on a running one; with one replica at a restart of the cluster (2.15.2) |

  The deletion on interest streams comes from the server's interest check (`checkInterestState()`), which cuts the
  head of the stream up to the lowest consumer floor without asking whether a message reached `max_deliver`; it runs
  every two minutes and at leader changes where a stream has replicas, and at a restart on a clustered server. The
  replicated work-queue case was fixed by nats-server PR #7845 (2.12.5). A message alone in the stream, with nothing
  acknowledged after it, stayed everywhere. Up to 5.9.0 this document said such a message always stays. In nats mode
  (`retry_handler: nats`) that message is the only record of a message that kept failing, so on the streams that
  delete it nothing is left; see the README's "Retry Handler Behavior".
- **NATS 2.9 now and then reports a redelivery as a first delivery.** It happens when the NAK's delay equals the
  expiry of the pull that is waiting, which is `max_batch_timeout`: with 200, 500 and 1000 ms for both, about 1
  message in 5 had one redelivery reported as delivery 1 (4 of 20, 6 of 30, 4 of 20 and at least 5 of 20). The
  delivery token really says 1, and the next delivery counts right again. It did not happen with unequal values
  (0 of 20, four times), with a plain NAK (0 of 30), or on 2.10.29, 2.11.17 and 2.15.2 (0 of 40 each). The retry
  count then reads one too low once, so Symfony repeats the first delay and allows one retry more. No message is
  lost. Both values are 1 second by default, and the retry strategy's jitter makes an exact match rarer.

## How the worker settles a retried message

Symfony's worker settles a message it sent for retry in one of two ways, and the transport NAKs the delivery
in both:

| Symfony Messenger | The worker calls | Why |
|---|---|---|
| 7.4.22, 8.1.10 and later | `ack()` | The retry was sent, so the message counts as handed over ([symfony/symfony#67038](https://github.com/symfony/symfony/pull/67038)). |
| up to 7.4.21 and 8.1.9 | `reject()` | The message failed. |

`ack()` of a delivery whose retry copy was left out must not reach NATS as an ACK: the message would be gone
after its first failure (#90). `reject()` of it must not TERM it either. So both look the delivery up among the
remembered ones first. A delivery that is not remembered takes the usual path: an ACK, or in symfony mode a TERM.

CI runs both: the PHP 8.5 leg installs the latest Messenger, and the lowest leg lowers `symfony/messenger` to a
release that rejects, 7.4.0 for the unit tests and 7.4.21 in the functional test app.
`scripts/check-messenger-rejects-a-retry.php` fails that leg if the installed release ever acknowledges instead.

## A message that cannot be decoded

- **Symfony Messenger 8.1 and later** hand such a failure to the worker as a message of its own, a
  `MessageDecodingFailedException`: their serializers (`PhpSerializer`, `Serializer`) return one, and the
  transport makes one when a serializer throws, as this transport's igbinary serializer does (5.10.0).
  FrameworkBundle's `DecodeFailedMessageMiddleware` decodes it once more and fails it, so it goes through the
  retry strategy like any failure: NAK retries, then a TERM and one failure-transport copy. Each redelivery
  carries its earlier deliveries as its retry count, as a decoded message does, so `max_retries` ends them.
- **When the serializer throws,** Messenger 8.1.11 and later fail it once, as unrecoverable (symfony/symfony
  commit 9e155bedfc, "Fix infinite retries when a serializer throws a decoding failure"), and 8.1.0 to 8.1.10
  retry it as above.
- **Before 8.1** the transport TERMs it when it is received, logs it at error level with its stream sequence, and
  goes on with the rest of the batch. Up to 5.9.x `get()` TERMed it and threw `MessageDecodingFailedException`,
  as Symfony's own transports do before 8.1, which stopped the worker.

## What was measured

With Symfony's own `Worker`, `SendFailedMessageForRetryListener` and `SendFailedMessageToFailureTransportListener`,
against nats-server 2.15 (2.15.1 and 2.15.2) and 2.10.29 in Docker, with client 2.29.0 and PHP 8.5, on Symfony
Messenger 8.1.11, whose worker acknowledges a retried message. The first six rows and the two that compare `copy`
with `nak` were run again on a release that rejects (7.3.0 or 7.4.21). Both servers and both ways of settling
gave the same results. Some rows name other servers.

| Case, `retry_via: nak` unless named | Result |
|---|---|
| `MultiplierRetryStrategy(3, 200, 2, 500)`, no jitter, the handler always fails | 4 runs; NAK retries after 0.20, 0.40 and 0.50 s (the third capped by `max_delay`); retry counts 0 to 3; `willRetry()` true three times, then false; then a TERM (MSG_TERMINATED), 1 failure-transport copy, 1 message stored, no warning logged |
| The handler fails once | handled on its NAK retry, 0.20 s later |
| `RecoverableMessageHandlingException(retryDelay: 700)` | redelivered 0.70 s later |
| `UnrecoverableMessageHandlingException` | 1 run, a TERM, 1 failure-transport copy |
| `max_deliver: 2` with `max_retries: 3`, the handler always fails | 4 runs, retry counts 0 to 3: the retry of the second delivery is published as a copy, which runs at once; then a TERM, 1 failure-transport copy, 2 messages stored, no MAX_DELIVERIES, nothing logged |
| `max_deliver: 2`, a `RecoverableMessageHandlingException` until the fifth run | 5 runs, handled on the fifth. With a NAK on the last delivery, as PR #113 had it: 2 runs, never handled, no failure-transport copy |
| The `reject()` of Messenger 8.1.9 and earlier, made by hand | redelivered 0.30 s later, with retry count 1 |
| A delivery nobody settled, `ack_wait: 1` | redelivered 1.00 s later, with retry count 1 |
| A message published with retry count 1 | 3 runs, retry counts 1, 2 and 3. With "the higher of the two counts wins", as PR #113 had it: 4 runs, counts 1, 1, 2 and 3 |
| A second consumer on the subject, the handler fails once | `copy`: that consumer receives 2 messages, and 2 are stored; `nak`: 1 and 1 |
| `max_ack_pending: 1`, the first message's retry waits 1.5 s | `copy`: the second message is handled at once; `nak`: 1.50 s later |
| A 0.3 s retry delay | `copy`: at once; `copy` with `scheduled_messages`: after 0.86 s on 2.15; `nak`: after 0.30 s |
| A NAK retry of 0.5 s on a consumer with `backoff` 1, 2, 3 s | redelivered after 0.50, 1.50 and 2.50 s |
| A 4 s NAK retry, `stream_max_age: 2` | the message leaves the stream before its retry |
| A 12 s NAK retry, `ack_wait: 2`, the server restarted 1 s after the NAK | redelivered once, with retry count 1, after 11.85 s (2.15.2) and 11.48 s (2.10.29) |
| A `DelayStamp` of `PHP_INT_MAX` | cut to 9,223,372,036,854 ms, and the message is held |
| A message that cannot be decoded, with this transport's igbinary serializer | Messenger 8.1.11: fails once as unrecoverable, then a TERM and 1 failure-transport copy; 7.4.21: TERMed when it is received, one error logged, the worker never gets it |
| The same with Symfony's `PhpSerializer` on Messenger 8.1 | through the retry strategy like any failure: NAK retries, then a TERM and 1 failure-transport copy |
| NATS 2.9.25, a NAK delay equal to `max_batch_timeout` (200, 500 and 1000 ms) | about 1 message in 5 has one redelivery reported as delivery 1 |

The examples run most of these rows against a live server, and CI runs the examples on the latest and on the
oldest supported NATS: `retry-via-nak.php`, `retry-via-nak-edge-cases.php`, `retry-via-nak-past-max-deliver.php`,
`retry-via-nak-server-behaviour.php`, `retry-via-nak-across-a-restart.php` and `retry-via-copy-or-nak.php`. The
Behat feature `nats_retry_via.feature` runs three of them through `messenger:consume`. The unit tests are listed
in [TESTS.md](TESTS.md).

## Choosing between `copy` and `nak`

| Use `nak` | Stay on `copy` |
|---|---|
| on NATS 2.9 to 2.11, where a copy cannot wait | when many messages may wait at once with long delays: each waiting retry holds a `max_ack_pending` slot |
| for delays shorter than a second, or exact ones | when the retries together may take longer than `stream_max_age`: a copy is a new message, with an age of its own |
| when a retry must not add a message to the stream | |
| when other consumers read the subject | |

With `nak`, give the retry strategy a `max_delay` and keep it below `stream_max_age`. `max_deliver` is optional:
left unset, or set above the retries you expect, it only caps deliveries nobody settles.
