# Examples

Runnable scripts, one per behaviour, that use the transport directly against a live NATS server. Each prints
`OK ...` when what it shows held, and fails otherwise, so they double as a check that the documented
behaviour still works. In an application the same DSN and options go under `framework.messenger.transports`.

| Example | Shows |
|---|---|
| `send-and-consume.php` | `setup()`, sending, the queue depth (`messenger:stats`), receiving and acknowledging |
| `duplicate-protection.php` | `DeduplicationIdStamp` and `deduplicate: true`: a message sent again is stored once, Symfony's retry is not |
| `request-timeout.php` | `request_timeout`, and sending again, with the same deduplication id, a message whose send timed out |
| `keepalive.php` | `keepalive()` from a SIGALRM handler, as `messenger:consume --keepalive` calls it (needs pcntl) |
| `connection-checks.php` | `ping_after_idle`, and a new dial once the connection is gone |

```bash
composer nats:start                 # the test server the examples default to
composer examples                   # runs them all; EXAMPLES_STRICT=1 also fails on a skipped one
php examples/keepalive.php          # or one at a time
```

`NATS_DSN` points them at another server, as `nats-jetstream://[user:password@]host:port`. Each example works
on a stream of its own and deletes it when done. `_bootstrap.php` holds what they share and is not an example.
