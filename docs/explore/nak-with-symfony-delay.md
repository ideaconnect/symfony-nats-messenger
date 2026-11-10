# Exploration: NAK with Symfony's computed retry delay (`retry_delay_source: symfony`)

Local prototype on branch `explore/nak-with-symfony-delay`, based on v5.8.0 (`ed3d49b`). Not pushed, not proposed
upstream. Measured on nats-server 2.15.0 in Docker, client 2.28.0, symfony/messenger 8.1.11, PHP 8.5.

## The design

Symfony computes the delay: `delay`, `multiplier`, `max_delay`, `jitter`, a custom strategy, or the delay a
`RecoverableExceptionInterface` carries. NATS redelivers the original message after that delay. A message whose
retries are used up stays in the stream, dead in place: no failure transport, the MAX_DELIVERIES advisory instead.

With `max_retries: 3` and `max_deliver: 4` there are four deliveries:

| Delivery | Symfony | Transport | NATS |
|---|---|---|---|
| 1 to 3 | retries, computes a delay | NAK with that delay | redelivers after it |
| 4 | does not retry (`willRetry()` false) | plain NAK, no delay | does not redeliver, keeps the message, publishes MAX_DELIVERIES |

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'nats-jetstream://host:4222/stream/topic'
                options:
                    retry_handler: nats
                    retry_delay_source: symfony   # new, default: nats
                    max_deliver: 4                # required: max_retries + 1
                    no_retry_action: nak          # new, default: nak; or term
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, jitter: 0.2 }
                # no failure_transport for this transport
```

## What 5.8.0 does instead

- `send()` leaves out Symfony's retry copy and with it the `DelayStamp` that holds the computed delay.
- The delivery is NAKed with `nak_delay` only.
- NATS redelivers the message as published, without a `RedeliveryStamp`, so Symfony's retry count is always 0: the
  multiplier never applies and `max_retries` never ends the retries (upstream issue #109).

## Code points

| Code point | Change |
|---|---|
| Builder | `retry_delay_source` (`nats`, `symfony`); `symfony` needs `retry_handler=nats` and `max_deliver`, a hard error otherwise. `no_retry_action` (`nak`, `term`). |
| `get()` → `withDeliveryCountAsRetryCount()` | A redelivered message gets `RedeliveryStamp(numDelivered - 1)`, read from the `$JS.ACK` reply subject. |
| `send()` | The retry copy is still not published. The delivery token is remembered with the `DelayStamp` delay. The #94 warning is not logged in this mode (unit test and live run 1 confirm). |
| `ack()` / `reject()` → `nakForRetry()` | NAK with Symfony's delay; a delay of 0 is a plain NAK. On the last delivery `max_deliver` allows, the delay is dropped. |
| `reject()` without a retry copy → `settleWhatSymfonyDoesNotRetry()` | Last delivery: plain NAK, always. Earlier delivery: `no_retry_action`. |
| `get()` decode failure | Unchanged: NAK with `nak_delay`, bounded by `max_deliver`. |

## Measured (examples/nats-retry-symfony-delays.php)

| # | Case | Result |
|---|---|---|
| 1 | `max_retries 3`, `max_deliver 4`, 1 s x2, jitter 0.2, `nak_delay=9` set | 4 handler runs. Symfony computed 1.17, 2.35, 3.57 s; NATS redelivered after 1.18, 2.36, 3.57 s. MAX_DELIVERIES (deliveries 4) 205 ms after the 4th NAK. Stream msgs 1, ack pending 0, pending 0. No warning. |
| 2 | `RecoverableMessageHandlingException(retryDelay: 2500)` | Symfony computed 2.50 s; redelivered after 2.51 s; handled on run 2. |
| 3 | Unrecoverable, `no_retry_action=nak` | 4 handler runs 0.01 s apart, MAX_DELIVERIES (deliveries 4), msgs 1. |
| 3 | Unrecoverable, `no_retry_action=term` | 1 handler run, MSG_TERMINATED (deliveries 1), msgs 1 on a limits stream, msgs 0 on a work-queue stream. |
| 4a | `max_retries 1`, `max_deliver 4` (too high) | 4 handler runs: 1.01 s, then 0.01 s, 0.01 s apart. MAX_DELIVERIES (4). Warning logged. |
| 4b | `max_retries 3`, `max_deliver 2` (too low) | 2 handler runs 1.01 s apart. MAX_DELIVERIES (2) 207 ms after the plain NAK. Warning logged. |
| 5 | First delivery pulled and never settled, `ack_wait 1 s` | The worker's first failure got Symfony's delay 2.00 s (retry 2 of 1 s x2); redelivered after 2.01 s. |
| 6 | Delay 2 h, `ack_wait 5 s` | See "Long delays". |

### When MAX_DELIVERIES is published

- After a **plain NAK** of the last delivery, the server queues the message for redelivery and finds the limit when
  the next pull request arrives. Measured 205 to 207 ms after the NAK, which is the worker's next pull
  (`max_batch_timeout` 0.2 s here). With no worker pulling, it waits for one.
- After a **NAK with a delay**, the server finds the limit when the delay has passed, without a pull. That is why
  the last delivery is NAKed without a delay: a 2 h delay would hold the advisory back 2 h.
- The message leaves the consumer's pending list at that moment (ack pending 0) and stays in the stream, on a
  limits stream and on a work-queue stream alike.

## `max_deliver` has to be `max_retries + 1`

| Setting | What happens |
|---|---|
| equal | The design above. |
| `max_deliver` higher | Symfony gives up at delivery `max_retries + 1`, NATS allows more. With `no_retry_action=nak` the handler runs again at once, back to back, until `max_deliver` (4a: two extra runs). With `term` the message is terminated there. |
| `max_deliver` lower | NATS stops at `max_deliver` while Symfony still retries: the later retries are never made (4b: 2 runs instead of 4). The transport drops the delay on that NAK so the advisory is not held back. |
| unset | Rejected when the transport is built. A message that keeps failing would otherwise come back at once, forever. |

**Recommended check.** The transport cannot see `max_retries`: the retry strategy lives in Symfony's container.
So the prototype does what it can at each level:

- Hard error at build time for what the transport can see: `max_deliver` missing, or `retry_handler` not `nats`.
- A warning, once per transport, the first time a mismatch shows at runtime (4a and 4b).
- For upstream, the right place for a hard error on `max_deliver != max_retries + 1` is the bundle's compiler pass,
  which can read both the transport options and the retry strategy. #94 already names that as the alternative.

## Deliveries nobody settled (requirement 3)

The retry count is `numDelivered - 1`, and NATS counts every delivery, so a delivery that ended by `ack_wait`
running out or by a crashed worker counts as a retry. Confirmed in run 5. Two consequences:

- The multiplier and `max_retries` stay right after a crash: a crash is not a free extra attempt.
- A crash on the last delivery leaves the message dead in place without the handler having failed on it. NATS
  publishes MAX_DELIVERIES when `ack_wait` runs out.

## A failure Symfony does not retry, before the last delivery (requirement 4)

An unrecoverable failure, or `willRetry()` false for any other reason, on delivery 1 to `max_deliver - 1` cannot
die in place at once: a NAK brings the message back, and only the last delivery ends in MAX_DELIVERIES.

| `no_retry_action` | Handler runs | Advisory | Message afterwards |
|---|---|---|---|
| `nak` (default) | `max_deliver` in all, back to back | MAX_DELIVERIES | Stays in the stream, any retention. |
| `term` | 1 | MSG_TERMINATED | Stays on a limits stream. **Removed** from a work-queue or interest stream: only the log and the advisory remain. |

Default `nak`: it never removes a message, on any retention, and every dead message ends the same way
(MAX_DELIVERIES), so one alert covers them all. Its cost is the extra handler runs, which hit a handler that
already said the failure is permanent. `term` is the better choice on a limits stream, where it keeps the message
and saves the runs; monitoring then has to watch MSG_TERMINATED too. The last delivery is NAKed either way.

## No failure transport (requirement 5)

Do not give these transports a `failure_transport`. Symfony's `SendFailedMessageToFailureTransportListener` sends
a copy whenever `willRetry()` is false, and the transport cannot stop it. With one configured:

- On the last delivery the failure transport gets a copy while the original also stays in the stream: the message
  exists twice.
- With `no_retry_action=nak`, an unrecoverable failure sends one copy per handler run, up to `max_deliver` copies.

A global `framework.messenger.failure_transport` applies to every transport, so it has to go too, with
`failure_transport` set on the other transports one by one.

The #94 warning ("Symfony's retry strategy retried a message that NATS redelivers itself") is not logged in this
mode. It is still logged with the default `retry_delay_source=nats`.

## Long delays

Server source (`consumer.go`, `processNak`): the delay is a Go `time.Duration`, an int64 of nanoseconds, with no
upper limit. The server sets the pending entry's timestamp to `now - ack_wait + delay`, so the delivery expires
`delay` from now, and `ack_wait` does not shorten it. The pending timer wakes every `ack_wait` and finds the
entry not yet due. The transport cuts a delay at 9 223 372 036 854 ms (292 years) so the nanoseconds fit a PHP
integer.

Run 6, Symfony delay 7200 s, `ack_wait` 5 s: watched for 180 s. Consumer info every 15 s showed ack pending 1,
redelivered 0, pending 0, delivered consumer_seq 1 throughout. The server was restarted (`docker restart`, file
storage) at 91 s and held the message the same way afterwards. A pull at 180 s got 0 messages, and the handler
ran once in all. So 36 `ack_wait` periods passed without a redelivery, and the hold survives a server restart.

While it waits, the message counts as one pending acknowledgement. That matters at scale: `max_ack_pending`
(1000 by default) stops deliveries to the consumer once that many messages are held, so many long delays can stall
it. The message can also leave the stream before the delay ends, through `stream_max_age` or another limit.

## Other interactions

- **`nak_delay`**: not used for a delivery that went through Symfony. Still used for an undecodable message.
- **`backoff`**: a plain NAK redelivers at once and never uses the backoff (measured 0.00 s with backoff 3/6 s).
  The backoff only times deliveries nobody settled. The server adds `backoff[step] - backoff[0]` to a delayed NAK
  from the second retry on (measured 1.00, 3.01, 5.00 s for a 1 s delay with backoff 2/4/6 s). Leave it unset.
- **`ack_wait`**: has to cover the handler; unrelated to the NAK delay.
- **Reconnect between `send()` and `ack()`**: the delay lives in process memory next to the delivery token, and
  the NAK takes the same path as on 5.8.0, so the #98 behaviour holds. If the process dies before the NAK, NATS
  redelivers after `ack_wait`, and that delivery counts as a retry.
- **Batching**: each envelope has its own message object, so the WeakMap keeps them apart.
- **WeakMap lifetime**: one entry per failed message, removed when it is settled, freed with the message otherwise.
- **Sub-second**: the NAK carries Symfony's milliseconds exactly. Measured error about 10 ms.

## Would upstream take it?

Plausibly, as an opt-in. No issue asks for exactly this.

- #47 and #90 established that the retry copy is never published in nats mode. This keeps that.
- #94 made retries-on in nats mode a warned misconfiguration. An explicit option separates intent from mistake.
- #50 documents that Symfony's delays otherwise need `scheduled_messages` and NATS 2.12.
- #109 (open, filed 2026-11-10) reports that `willRetry()` is constant in nats mode because no `RedeliveryStamp`
  exists. The `get()` change here fixes that for this mode.

Likely review points: the `max_deliver` requirement, the bundle-level check, `no_retry_action` and its default,
the backoff interplay, and whether the retry count should be set in the default mode too.

## Not done in the prototype

README, CHANGELOG and TESTS.md entries, a Behat feature, the bundle's compile-time check, and a mutation run.
