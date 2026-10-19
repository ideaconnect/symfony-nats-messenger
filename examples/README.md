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
| `reconnect.php` | `reconnect: true`: a worker polls through a restart of its server, the pull in flight ends empty (`409 Server Shutdown`), `auto_setup` recreates the memory stream the restart lost, and the client logs through the transport's logger (needs docker) |
| `cluster-failover.php` | `reconnect: true` on a cluster: the server a worker is connected to stops, the client fails over to a server it learned from the cluster, and the worker receives everything (needs the cluster and docker) |
| `stream-placement.php` | `stream_replicas`, `stream_placement_tags` and `stream_placement_cluster` on a cluster: replicas on tagged servers only, one option changing only its own half of a placement, a move and scale refused in one update, then staged (needs the cluster) |

```bash
composer nats:start                 # the test servers the examples default to, the cluster included
composer examples                   # runs them all; EXAMPLES_STRICT=1 also fails on a skipped one
php examples/keepalive.php          # or one at a time
```

`NATS_DSN` points the single-server examples at another server, as `nats-jetstream://[user:password@]host:port`.
Each example works on a stream of its own and deletes it when done. `_bootstrap.php` holds what they share and is
not an example.

## The test cluster

`composer nats:start` also runs a JetStream cluster named `east` of three servers (`tests/nats/nats-cluster*.conf`),
on `127.0.0.1:4226`, `4227` and `4228`. Each server is named after its container:

| Server (and container) | Port | `server_tags` |
|---|---|---|
| `nats_cluster_1` | 4226 | `disk:ssd`, `zone:a` |
| `nats_cluster_2` | 4227 | `disk:ssd`, `zone:b` |
| `nats_cluster_3` | 4228 | `disk:hdd`, `zone:c` |

Each one advertises its published port to clients (`client_advertise`), so a client on the host that connects
to one of them learns the other two and can fail over to them. `cluster-failover.php` and `reconnect.php` take a
server away with `docker stop` or `docker restart` and start it again before they end. The cluster examples
always run against this cluster, whatever `NATS_DSN` says, and print `SKIP` when it is not running;
`reconnect.php` prints `SKIP` when `NATS_DSN` is set, since it restarts the test server `nats_test`.
