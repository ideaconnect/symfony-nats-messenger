# `give_up_via: nak` - dead in place

Status: a local proposal on branch `feat/dead-in-place`, on top of 5.9.0. Not proposed upstream.

## What it does

With `retry_via: nak`, Symfony's retry strategy decides whether and when NATS redelivers a failed message
(see [symfony_retry_via.md](symfony_retry_via.md)). `give_up_via` says what happens when Symfony gives up:

| `give_up_via` | A message Symfony gives up on |
|---|---|
| `term` (default, 5.9.0 behaviour) | TERMed. A failure transport gets one copy. A limits stream keeps it, a work-queue or interest stream removes it. |
| `nak` | Stays in the stream, whatever its retention. NATS publishes MAX_DELIVERIES. No failure transport gets a copy. |

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: 'nats-jetstream://host:4222/stream/topic'
                options:
                    retry_via: nak
                    give_up_via: nak
                    max_deliver: 4        # required: the retry strategy's max_retries + 1
                retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2 }
```

With `max_retries: 3` and `max_deliver: 4`: deliveries 1 to 3 are NAKed with Symfony's delay (that is
`retry_via: nak`, unchanged). Symfony gives up on delivery 4. The transport NAKs it without a delay. NATS
redelivers nothing after the last delivery `max_deliver` allows, keeps the message, and publishes MAX_DELIVERIES at
the next pull.

## Where it is hooked

| Place | What it does |
|---|---|
| `Options/GiveUpVia.php` | The enum `TERM`, `NAK`. |
| `Options/TransportOption.php` | The keys `give_up_via` and `transport_name`. |
| `NatsTransportConfigurationBuilder::resolveGiveUpVia()` | Refuses `nak` without `retry_via: nak`, and without `max_deliver`. |
| `NatsTransportConfiguration::giveUpVia()`, `transportName()` | The accessors. `transport_name` is the option FrameworkBundle adds to every transport it creates. |
| `NatsTransport::reject()` → `leavesInTheStreamByANak()` | The one decision point. `reject()` is what Symfony's Worker calls when `willRetry()` is false. A plain NAK instead of the TERM of `handleFailedDelivery()`. |
| `NatsTransport::get()` → `withoutAFailureTransportCopy()` | Adds a `SentToFailureTransportStamp` naming the transport to every received message. |

Nothing changes in `send()`, `ack()` or `nakForRetry()`: retries work as in 5.9.0.

## The two problems and how each is solved

**1. The failure transport would get a copy too.** This is why upstream kept "dead in place" out of #113:
Symfony's `SendFailedMessageToFailureTransportListener` sends a copy whenever `willRetry()` is false, and a
transport cannot stop a listener. But the listener skips a message that carries a `SentToFailureTransportStamp`
whose receiver name is the transport it came from. Symfony's own comment calls that the way to "opt out of the
failure transport". So `get()` adds that stamp. It needs the transport's name, which FrameworkBundle passes as the
option `transport_name`. A transport built by hand without it adds no stamp, and a failure transport then gets its
copy.

**2. A message Symfony gives up on before the last delivery.** That is a failure Symfony never retries
(`UnrecoverableMessageHandlingException`), or a `max_deliver` above `max_retries + 1`. Only the last delivery can
end in MAX_DELIVERIES; a NAK on an earlier one brings the message back.

- On a stream with limits retention a TERM keeps the message. So it is TERMed, and its handler runs once.
- On a stream configured with work-queue or interest retention a TERM removes it. So it is NAKed without a delay:
  its handler runs again at once, up to `max_deliver` times, and the last delivery leaves it in the stream.

The retention is read from the transport's `stream_retention` option. Without the option the stream is taken to
have limits retention, the server's default.

## Measured

nats-server 2.15.0 in Docker, client 2.29.0, symfony/messenger 8.1.11, Symfony's own Worker with its retry and
failure-transport listeners (`examples/give-up-via-nak.php`, 15 s). Retry strategy 3 retries, 200 ms doubling,
`max_deliver: 4`.

| Case | Result |
|---|---|
| Keeps failing, limits stream | 4 runs, retries 0.21, 0.40, 0.81 s apart. MAX_DELIVERIES. 1 message in the stream, 0 failure-transport copies. |
| Keeps failing, work-queue stream | The same: 1 message in the stream. |
| Unrecoverable, limits stream | 1 run, MSG_TERMINATED. 1 message in the stream, 0 copies. |
| Unrecoverable, work-queue stream | 4 runs back to back, MAX_DELIVERIES. 1 message in the stream, 0 copies. |
| Keeps failing, transport without a name | 1 message in the stream, and 1 failure-transport copy. |

## Limits

- `max_deliver` has to be `max_retries + 1` for the message to end in MAX_DELIVERIES. Lower: 5.9.0 publishes the
  retry of the last delivery as a copy, and that copy is a new message with its own deliveries. Higher: Symfony
  gives up early, which is case 2 above.
- A delivery nobody settled (a worker that crashed) counts as a retry and as a delivery.
- The stamp is on every received message of such a transport, so a failure transport never gets anything from it.
- The retention is the configured one. A stream that exists with another retention than the option says is not
  detected.
- No README, CHANGELOG or TESTS.md entries, no Behat feature and no mutation run yet.
