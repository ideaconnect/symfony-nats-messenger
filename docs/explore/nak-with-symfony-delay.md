# Exploration: NAK with Symfony's computed retry delay (`retry_delay_source: symfony`)

Local prototype on branch `explore/nak-with-symfony-delay`, based on v5.8.0 (`ed3d49b`). Not pushed, not proposed
upstream. Measured on nats-server 2.15.0, client 2.28.0, symfony/messenger 8.1.11, PHP 8.5.

## The question

"If we want to use Symfony's delay and jitter with `retry_handler=nats`, will it not work?" On 5.8.0 it does not:

- `send()` leaves out Symfony's retry copy and with it the `DelayStamp` that holds the computed delay.
- The delivery is NAKed with `nak_delay` only (or a plain NAK).
- NATS redelivers the message as published, without a `RedeliveryStamp`, so Symfony's retry count is always 0.
  The multiplier never applies and `max_retries` never ends the retries (this is upstream issue #109). Measured
  with a strategy of 3 s x2: Symfony computed 3.00 s, 3.00 s and NATS redelivered after 1.00 s, 1.00 s (`nak_delay`).

## The option

`retry_delay_source`: `nats` (default, behaviour unchanged) or `symfony`. `symfony` is only accepted together with
`retry_handler=nats`; the builder rejects it otherwise. With it:

| Code point | Change |
|---|---|
| `get()` → `withDeliveryCountAsRetryCount()` | A redelivered message gets `RedeliveryStamp(numDelivered - 1)`, read from the `$JS.ACK` reply subject. Symfony's strategy then sees the real retry count. |
| `send()` | The retry copy is still not published. The delivery token is remembered together with the `DelayStamp` delay. The #94 warning is not logged: the strategy is on by intent. |
| `ack()` / `reject()` → `nakForRetry()` | The remembered delivery is NAKed with Symfony's delay (`nakWithDelay`); a delay of 0 is a plain NAK. `nak_delay` plays no part. |
| `reject()` without a retry copy | TERM instead of NAK: Symfony will not retry (no retries left, or an unrecoverable failure). |
| `get()` decode failure | Unchanged: NAK with `nak_delay`, bounded by `max_deliver`. Symfony's strategy never sees such a message. |

Net effect: NATS still redelivers the original message (no copy, no duplicate), Symfony decides whether and when.

## Measured (examples/nats-retry-symfony-delays.php)

| Case | Symfony computed | NATS redelivered after |
|---|---|---|
| multiplier, 1 s x2, jitter 0 (`nak_delay=9` set and ignored) | 1.00, 2.00, 4.00 s | 1.01, 2.01, 4.01 s |
| jitter 0.5 on 2 s | 2.18, 2.63, 1.33 s | 2.19, 2.64, 1.33 s |
| `RecoverableMessageHandlingException(retryDelay: 2500)` | 2.50 s | 2.51 s |
| `max_retries: 2`, handler always fails | 0.5, 0.5 s | 3 handler runs, third TERMed, nothing pending |
| consumer `backoff` 2/4/6 s, Symfony delay 1 s | 1, 1, 1 s | 1.00, 3.01, 5.00 s |
| `max_deliver=2`, `max_retries: 3` | - | 2 handler runs, no redelivery, warning logged |

## Interactions

- **`nak_delay`**: not used for a delivery Symfony retries. Still used for an undecodable message.
- **`backoff`**: a plain NAK redelivers at once and does not use the backoff (measured: 0.00 s with backoff 3/6 s).
  The backoff only times deliveries nobody settled (a crashed worker). But the server adds `backoff[step] -
  backoff[0]` to a delayed NAK from the second retry on (it offsets the timestamp by `ack_wait`, which is the first
  step, and compares against the current step). That is server behaviour and affects `nak_delay` today as well.
  So with Symfony's delays, leave `backoff` unset, or accept the added time.
- **`ack_wait`**: unrelated to the NAK delay, which counts from the NAK. It still has to cover the handler.
- **`max_deliver` vs `max_retries`**: Symfony allows `max_retries + 1` deliveries and TERMs the last one, so it
  reaches the failure transport. NATS allows `max_deliver`. Effective limit is `min(max_retries + 1, max_deliver)`.
  If `max_deliver <= max_retries`, Symfony retries the last delivery NATS allows, NATS does not redeliver it, and
  the message never reaches a failure transport; it stays in the stream (limits retention). The transport logs a
  warning once when it sees that. Rule: leave `max_deliver` unset or set it above `max_retries + 1`.

## Failures Symfony does not retry

On 5.8.0 a rejection in nats mode is NAKed with `nak_delay`, so NATS keeps redelivering up to `max_deliver`. With
`retry_delay_source=symfony` that would be wrong: Symfony has already sent the message to the failure transport
(or dropped it), and every redelivery would fail for good again and add another failure-transport copy. So the
prototype TERMs it. This also makes `UnrecoverableMessageHandlingException` work as Symfony documents it.

## Edge cases

- **Delay above limits**: the client sends nanoseconds as a PHP int; the delay is cut at 9 223 372 036 854 ms
  (292 years). The server has no smaller cap. A message can still expire (`stream_max_age`) before a long delay
  ends, and it counts against `max_ack_pending` while it waits, so many long delays can stall a consumer.
- **Sub-second**: Symfony's delay is in whole milliseconds and the NAK carries it exactly. Measured error ~10 ms.
- **Delay 0 or negative, or no `DelayStamp`**: plain NAK, immediate redelivery.
- **Reconnect between `send()` and `ack()`**: the delay lives in process memory next to the delivery token, and
  the NAK takes the same path as on 5.8.0 (`checkTheConnectionBeforeSettlingAFailure()`), so the #98 behaviour
  holds. If the process dies before the NAK, NATS redelivers after `ack_wait` (or the backoff), not Symfony's delay.
- **Keepalive / `ack_wait` expiring inside the handler**: unchanged. If `ack_wait` expires, NATS redelivers on
  its own and that delivery counts as a retry for Symfony.
- **Batching**: each envelope has its own message object, so the WeakMap keeps them apart.
- **WeakMap lifetime**: one entry per failed message, removed when it is settled, freed with the message otherwise.
- **Retry count source**: `numDelivered - 1` also counts redeliveries after a worker crash or an `ack_wait`
  timeout as retries. That is arguably right, but differs from Symfony's own transports.
- **Failure-transport copy**: carries `RedeliveryStamp(0)` last, so it is still published.

## Would upstream take it?

Plausibly, as an opt-in. No issue asks for exactly this. Related history:

- #47 and #90 established "nats mode ignores Symfony's strategy" and that the retry copy is never published.
  This keeps both: no copy is published, the default is unchanged.
- #94 made retries-on in nats mode a warned misconfiguration. An explicit option is what separates intent from
  mistake, so the warning stays for the default.
- #50 documents that Symfony's delays otherwise need `scheduled_messages` and NATS 2.12. This gives delays on any
  server version.
- #109 (open, filed 2026-11-10) reports that `willRetry()` is constant in nats mode because no `RedeliveryStamp`
  exists. The `get()` change here is the fix for half of it; the other half (`max_retries: 0` vs `max_deliver`)
  is the default mode and is not touched.

Likely review points: the TERM on a non-retried failure, the backoff interplay, whether the retry count should be
injected in the default mode too (#109), and a Behat scenario plus README section, which the prototype lacks.

## Not done in the prototype

README, CHANGELOG and TESTS.md entries, a Behat feature, the bundle's compile-time view of `max_retries`, and a
mutation run.
