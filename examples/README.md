# Examples

Runnable scripts, one per behaviour, that use the transport directly against a live NATS server. Each prints
`OK ...` when what it shows held, and fails otherwise, so they double as a check that the documented
behaviour still works. In an application the same DSN and options go under `framework.messenger.transports`.

| Example | Shows |
|---|---|
| `send-and-consume.php` | `setup()`, sending, the queue depth (`messenger:stats`), receiving and acknowledging |
| `duplicate-protection.php` | `DeduplicationIdStamp` and `deduplicate: true`: a message sent again is stored once, Symfony's retry is not |
| `nats-retry.php` | `retry_handler: nats` under Symfony's Worker with FrameworkBundle's default retry strategy: a handler that fails once runs again when NATS redelivers the message, `nak_delay` later, and Symfony's retry is not published; the transport warns once that Symfony's retry strategy is on, and names `retry_via: nak`; a message that cannot be decoded is TERMed, as Symfony's own transports settle it: on Symfony Messenger 8.1 and later the worker fails it once as the decoding failure, before 8.1 the transport TERMs it and the decode error stops the first worker; an unrecoverable failure runs once |
| `nats-retry-several-handlers.php` | `retry_handler: nats` under Symfony's Worker with its error-details and retry listeners and `AddUnrecoverableFailureStampListener`, over a message with two handlers, one failing for good and the other once: with `max_retries` 0 and 3, in either order of the handlers, JetStream publishes MSG_NAKED for the first delivery and MSG_TERMINATED for the second, where the unrecoverable failure is left alone, and each handler runs twice; two handlers failing for good are TERMed at the first delivery |
| `retry-via-nak.php` | `retry_via: nak` under Symfony's Worker with its retry and failure-transport listeners: a message that keeps failing is retried through NAKs with the strategy's delays (0.2, 0.4 and 0.5 s) at retry counts 0 to 3, then TERMed with one copy in the failure transport; one that fails once is handled on its retry; a `RecoverableMessageHandlingException`'s own delay replaces the strategy's; an unrecoverable failure runs once; a retry without a delay is a plain NAK |
| `retry-via-nak-edge-cases.php` | `retry_via: nak` at its edges: a delivery nobody settled counts as a retry; a message that cannot be decoded goes to the worker as the decoding failure on Symfony Messenger 8.1 and later, through the retry strategy or once as unrecoverable, and before 8.1 is TERMed at once, with `get()` throwing the decode error; the `reject()` of Messenger 7.4.21, 8.1.9 and earlier is NAKed as the `ack()` of later releases is; a message published with a retry count keeps it; the builder refuses `retry_via` in nats mode, and `nak_delay` and `backoff` with `nak`; a consumer whose `max_deliver` the option does not say decides the last delivery |
| `retry-via-nak-past-max-deliver.php` | `retry_via: nak` with `max_deliver: 2`: the retry of the last delivery `max_deliver` allows goes out as a copy, so a message that keeps failing still runs `max_retries` + 1 times and reaches the failure transport, and one whose handler throws a `RecoverableMessageHandlingException` until its fifth run is handled |
| `retry-via-nak-server-behaviour.php` | what the server does with a NAK retry it holds: a delay too long for it is cut and the message held, a retry that waits longer than `stream_max_age` loses its message, and a consumer's `backoff` adds `backoff[n] - backoff[0]` to each retry |
| `retry-via-nak-across-a-restart.php` | a NAK retry that waits while the server restarts is still redelivered, once, up to a second early (needs docker) |
| `retry-via-copy-or-nak.php` | the same failure under `retry_via: copy` and `nak`: what a second consumer on the subject receives and the stream stores, what a waiting retry holds back with `max_ack_pending: 1`, and how long a 0.3 s retry waits, with `scheduled_messages` as well on NATS 2.12 and later |
| `delayed-messages.php` | `scheduled_messages`: a message sent with a `DelayStamp` arrives when its time has come, on NATS 2.12 and later; on an older server `setup()` and the send of a delayed message are refused, so nothing is stored that would never be delivered; on a stream with interest retention `setup()` is refused until a consumer has interest in the delayed subjects |
| `request-timeout.php` | `request_timeout`, and sending again, with the same deduplication id, a message whose send timed out |
| `keepalive.php` | `keepalive()` from a SIGALRM handler, as `messenger:consume --keepalive` calls it (needs pcntl) |
| `connection-checks.php` | `ping_after_idle`, and a new dial once the connection is gone |
| `jwt-login.php` | `jwt` with `nkey_seed` logs in as a user of a server in operator mode, for `setup()`, sending and receiving; the JWT with another user's seed and an expired JWT are refused by the server, and the JWT alone or a seed in the `jwt` option when the transport is built |
| `nkey-login.php` | `nkey_seed` alone logs in as a user the server knows by its public key, for `setup()`, sending and receiving; a seed the server does not know is refused by the server, and the public key alone or a seed next to a password when the transport is built |
| `token-login.php` | `token` logs in to a server whose clients share one token, for `setup()`, sending and receiving; a wrong token is refused |
| `reconnect.php` | `reconnect: true`: a worker polls through a restart of its server, the pull in flight ends empty (`409 Server Shutdown`), `auto_setup` recreates the memory stream the restart lost, and the client logs through the transport's logger (needs docker) |
| `cluster-failover.php` | `reconnect: true` on a cluster: the server a worker is connected to stops, the client fails over to a server it learned from the cluster, and the worker receives everything (needs the cluster and docker) |
| `handler-failing-during-failover.php` | both retry modes with `reconnect: true` on a cluster, under Symfony's Worker and retry listener: the handler stops the worker's server and fails, and the worker carries on through another server. In symfony mode Symfony's retry copy is stored there and handled at once; in nats mode the NAK reaches the cluster and the message comes back `nak_delay` later (needs the cluster and docker) |
| `handler-succeeding-during-failover.php` | both retry modes with `reconnect: true` on a cluster, under Symfony's Worker: the handler stops the worker's server and succeeds, the ACK reaches the cluster through another server, and the message is not delivered again (needs the cluster and docker) |
| `nats-retry-on-a-cluster.php` | `retry_handler: nats` with `max_deliver: 2` and three replicas on the cluster: `setup()` warns once about a stream with interest retention, and NATS deletes the message stopped at `max_deliver` from it within seconds, while a work-queue stream keeps it from NATS 2.12.5 on and `setup()` says nothing |
| `stream-placement.php` | `stream_replicas`, `stream_placement_tags` and `stream_placement_cluster` on a cluster: replicas on tagged servers only, one option changing only its own half of a placement, a move and scale refused in one update, then staged (needs the cluster) |

```bash
composer nats:start                 # the test servers the examples default to, the cluster included
composer examples                   # runs them all; EXAMPLES_STRICT=1 also fails on a skipped one
php examples/keepalive.php          # or one at a time
```

`NATS_DSN` points the single-server examples at another server, as `nats-jetstream://[user:password@]host:port`.
Each example works on a stream of its own and deletes it when done. `_bootstrap.php` holds what they share, and
`_retry_via.php` what the `retry-via-*` examples share (Symfony's Worker with its retry and failure-transport
listeners); neither is an example.

## The login examples

A login example needs a server that knows the credentials it shows, so `NATS_DSN` does not move it:

| Example | Test server (and container) | Port |
|---|---|---|
| `nkey-login.php` | `nats_test` (`tests/nats/nats.conf`) | 4222 |
| `jwt-login.php` | `nats_test_jwt` (`tests/nats/nats-jwt.conf`, in operator mode) | 4229 |
| `token-login.php` | `nats_test_token` (`tests/nats/nats-token.conf`) | 4230 |

Each prints `SKIP` when its server is not running. `nkey-login.php` also prints `SKIP` when `NATS_DSN` is set, since it
shares port 4222 with the server `NATS_DSN` replaces. `jwt-login.php` and `nkey-login.php` print `SKIP` as well when the
credentials are missing, which `composer nats:start` generates (`tests/nats/auth/README.md`). `EXAMPLES_STRICT=1` fails
the run on any of these skips, as it does for the others.

## The test cluster

`composer nats:start` also runs a JetStream cluster named `east` of three servers (`tests/nats/nats-cluster*.conf`),
on `127.0.0.1:4226`, `4227` and `4228`. Each server is named after its container:

| Server (and container) | Port | `server_tags` |
|---|---|---|
| `nats_cluster_1` | 4226 | `disk:ssd`, `zone:a` |
| `nats_cluster_2` | 4227 | `disk:ssd`, `zone:b` |
| `nats_cluster_3` | 4228 | `disk:hdd`, `zone:c` |

Each one advertises its published port to clients (`client_advertise`), so a client on the host that connects
to one of them learns the other two and can fail over to them. `cluster-failover.php`,
`handler-failing-during-failover.php`, `handler-succeeding-during-failover.php`, `reconnect.php` and
`retry-via-nak-across-a-restart.php` take a server away with `docker stop` or `docker restart` and start it again
before they end. The cluster examples always run against this cluster, whatever `NATS_DSN` says, and print `SKIP`
when it is not running; `reconnect.php` and `retry-via-nak-across-a-restart.php` print `SKIP` when `NATS_DSN` is
set, since they restart the test server `nats_test`.
