# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **With `deduplicate`, a message routed to two transports on one stream is stored for each.** Symfony hands
  each transport a message is routed to the envelope the one before returned, deduplication id included, and
  JetStream deduplicates per stream whatever the subject, so the second transport's copy was dropped, and
  so were the failure-transport copies of a message that failed on two transports. The `Nats-Msg-Id` now
  holds the transport's subject too (`<id>:<topic>:<retry count>`), and on a failure-transport copy the
  transport the message failed on (`:failed:<receiver>`). Every id changes with the upgrade, so a message
  sent once before it and again after it, within the stream's `duplicate_window`, is stored twice, and so
  is one that processes still on 5.4.0 and processes already upgraded both send, as during a rolling deploy.
- **An empty pull counts as using the connection.** It was counted only when messages came, so with
  `max_batch_timeout` at or above `ping_after_idle` every pull after an empty one sent a PING first, with
  one `connection_timeout` for the answer, and one slow answer dropped a healthy connection.
- **A pull the server did not answer makes the next operation check the connection.** The client reports a
  pull that got no answer before its own deadline, `max_batch_timeout` plus a second, as a JetStream error
  with status 408, like the server's own end of a pull that found no messages, so it read as an empty
  batch and called for no check. A half-open connection takes every write and delivers nothing: a consumer
  on one pulled empty batches until the client's heartbeat noticed, about 90 seconds with its defaults. The
  pull still reads as empty, and the next operation PINGs first, so the pull after it runs on a new
  connection.
- **Docs:** the README said the check after a failure catches a connection that silently stopped delivering,
  which `get()` did not do until the fix above. It also gives the PING's real bound, `connection_timeout` or
  the shorter `request_timeout`, for both checks. `CLAUDE.md`, `AGENTS.md` and `STRUCTURE.md` describe the
  behaviour added since 5.2.0.

### Added
- **A unit test that runs the real client for the unanswered pull**, against an in-memory server, so a
  client version that words its own pull deadline differently fails the suite instead of turning the check
  off.
- **Functional scenarios for the idle-connection check**, against a new test server that drops idle clients
  within seconds (`nats-stale`, port 4225): a message sent after the server dropped the idle connection goes
  out, and without the check it fails.
- **A functional scenario for duplicate protection across two transports on one stream**, which fails on
  5.4.0 with one message stored instead of two.
- **Runnable examples** in `examples/`, one per behaviour: sending and consuming, duplicate protection, the
  request timeout, keepalive from a signal handler, and the connection checks. Each prints `OK` when what it
  shows held. `composer examples` runs them against the test server, and CI runs them after the functional
  suite on the latest and the oldest supported NATS.
- **Unit tests for configuration checks that no test covered.** A mutation run whose mutants really ran
  the tests changed each of these without a test failing: the port in the DSN, an upper-case `+tls` scheme
  or `stream_storage` value, timeouts below half a millisecond or set to `null`, the client's reconnect and
  pedantic mode staying off, the `inactive_threshold` check, each tri-state stream flag after the first,
  zero and fractional `backoff` entries, `max_deliver` without `backoff`, a `null` `stream_max_age` with a
  duplicate window, and credential options given as numbers, booleans or arrays.

### Changed
- **The functional suite's TLS certificates are generated, not committed (#9).** The repository held the
  private keys of the test CA, server and client certificates, which security scanners report as a leaked
  secret even though they were test-only. `tests/nats/certs/generate.sh` now creates the set with `openssl`
  on each machine, `composer nats:start` (and the Behat context, when it starts NATS itself) runs it first,
  and the files are ignored by git. The keys stay in the history, but nothing uses them any more. A set
  that is about to expire is replaced as well, so that a long-lived checkout does not run the TLS
  scenarios on expired certificates.

## [5.4.0] - 2026-10-04

A **minor** release. It adds duplicate protection, the `deduplicate` option and `DeduplicationIdStamp`
(#53), and makes `getMessageCount()` give up after one connection attempt when NATS cannot be reached (#54).
Nothing changes unless the new option or the new stamp is used.

### Added
- **Duplicate protection: the `deduplicate` option and `DeduplicationIdStamp` (#53).** A `send()` that timed
  out may still have stored the message, so dispatching it again stored it twice, and so did Symfony when a
  worker stopped after a retry or failure-transport send that timed out and NATS redelivered the original.
  The transport now publishes the id of a `DeduplicationIdStamp` as `Nats-Msg-Id`, followed by Symfony's
  retry count and a marker on the failure-transport copy, so JetStream drops a copy sent again within
  `stream_duplicate_window` while each retry stays a message of its own. With `deduplicate: true` every
  message gets an id on its first send, which travels with it; an application adds its own stamp to cover a
  message it dispatches again as a new envelope. Off by default, so nothing changes unless it is set or a
  stamp is added.

### Fixed
- **`getMessageCount()` against a server that cannot be reached tries to connect once, not twice (#54).**
  When the consumer lookup failed, the stream-level fallback dialled again, so `messenger:stats` against a
  server that was down waited 12 seconds for a refused connection and 18 seconds for one that did not
  answer, with the default `connection_timeout`. It now gives up after the first attempt; a lookup that
  failed on an open connection still falls back. It still returns 0 in that case, as documented: the
  README now says that an outage reads as an empty queue there.

## [5.3.0] - 2026-10-04

A **minor** release. It adds the `request_timeout` option (#51), requires `idct/php-nats-jetstream-client`
`^2.10` (#52), and corrects several places in the documentation (#55). Existing configurations behave as
before: `request_timeout` defaults to the 10 seconds every request has waited until now.

### Added
- **`request_timeout` option (#51).** How long an operation waits for the server's reply was fixed at the
  client's default of 10 seconds, and no option changed it: the publish acknowledgement of `send()`, an ACK
  with `ack_sync`, and the JetStream API calls of `setup()` and `getMessageCount()` (`connection_timeout`
  covers only the dial, and `max_batch_timeout` only the pull). `request_timeout` sets it, in seconds, from
  the DSN query or the transport options. It defaults to 10, so nothing changes unless it is set.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.10` instead of `^2.4` (#52).** The older releases still
  passed the unit tests, but later ones fixed bugs on paths this transport uses: 2.5.2 the reconnect-disabled
  path a dropped connection takes, 2.5.3 a publish acknowledgement with neither an error nor a stream that
  was accepted as success, 2.9.0 one timeout for the whole request, and 2.10.0 a pull after an unnoticed
  server restart, which now fails as a lost connection and leaves the client Closed, so the re-dial added
  in 5.2.0 sees it at once. CI ran the functional suite against the client's unreleased `dev-main`; it now
  runs it, with the unit tests, against the lowest client release allowed (on PHP 8.2) and the latest one
  (on PHP 8.5).

### Fixed
- **Documentation corrections from the 5.1.0 review (#55).** `get()`'s docblock, a comment, `CLAUDE.md` and
  `STRUCTURE.md` said that a missing consumer reads as an empty pull (status 404); a missing consumer
  reports 503, which only `auto_setup` recovers from, and otherwise `get()` throws. `STRUCTURE.md` described
  a `send()` path through `requestWithHeaders()` that no longer exists. The README said a lower
  `connection_timeout` fails faster on any connection problem; a refused connection takes about 6 seconds
  whatever the value, since a failing dial is tried three times. An old changelog entry said `close()` runs
  on worker shutdown; Symfony never calls it. `send()`'s docblock now lists `ConnectionException` and
  `TimeoutException`. Two stray fragments in the README were moved or removed.

## [5.2.3] - 2026-10-03

A **patch** release with a security fix: a `tls_verify_peer` value the transport does not recognize no
longer turns TLS peer verification off (#42). The README now also says that Symfony's retry delays need
`scheduled_messages` (#50). The public PHP API and the requirements are unchanged.

### Fixed
- **The README now says that Symfony's retry delays need `scheduled_messages: true` (#50).** Symfony's
  retry strategy waits between attempts by re-sending the failed message with a `DelayStamp`, which the
  transport applies only when `scheduled_messages` is enabled (NATS 2.12 or later). With the defaults every
  retry ran straight after the failure, and only the scheduled-messages section of the README mentioned that
  a `DelayStamp` is ignored. The retry section now says so too, and names the alternative for older servers:
  `retry_handler: nats` with `nak_delay` or `backoff`, `max_deliver`, and `max_retries: 0`.

### Security
- **An unrecognized `tls_verify_peer` value no longer turns TLS peer verification off (#42).** It is the
  only boolean option that defaults to `true`, but it went through the same coercion as the others, which
  turns any value outside `1`, `true`, `yes` and `on` into `false`. So `tls_verify_peer=enabled`, `y`, an
  empty value (`?tls_verify_peer=`, easy to get from an unset environment variable in a templated DSN) or a
  `null` option made the transport connect over TLS without verifying the server's certificate, and said
  nothing. Now only `false`, `0`, `no` or `off` turns verification off, and any other value keeps it on. A
  transport configured with such a value whose server certificate cannot be verified now fails the TLS
  handshake instead of connecting unverified; set `tls_verify_peer: false` if that is really intended.

## [5.2.2] - 2026-10-03

A **patch** release with one fix: `messenger:consume --keepalive` no longer stops the worker, or fails the
message it is handling, at the first alarm (#48). The README has a new section on `--keepalive`. The public
PHP API and the requirements are unchanged.

### Fixed
- **`messenger:consume --keepalive` no longer breaks the worker at the first alarm (#48).** Symfony calls
  `keepalive()` from its `SIGALRM` signal handler, and `keepalive()` waited there for the in-progress
  acknowledgement, which PHP does not allow inside a signal handler: it failed with "Cannot switch fibers in
  current execution context". Depending on where the alarm caught the handler, the worker died, or the
  message it was handling failed with that error and was retried. `keepalive()` now queues the
  acknowledgement and returns without waiting. It goes out the next time the event loop runs: at once when
  the handler waits on asynchronous work, such as a call to NATS, but only after the handler returns when it
  only blocks, so such a handler needs an `ack_wait` longer than its longest run. Without a connection,
  `keepalive()` now sends nothing instead of dialling, and a failed acknowledgement no longer surfaces from
  it. The README has a new section on `--keepalive`.

## [5.2.1] - 2026-10-03

A **patch** release with two fixes to how a failed message is retried: the recommended `IgbinarySerializer`
no longer stops the worker when Symfony retries a message or sends it to the failure transport (#46), and
with `retry_handler: nats` Symfony's retry no longer multiplies the deliveries (#47). The public PHP API and
the requirements are unchanged.

### Fixed
- **Retrying a failed message no longer crashes the worker when the transport uses `IgbinarySerializer`
  (#46).** `AbstractEnveloperSerializer::encode()` serialized the whole envelope, including the stamps
  Symfony marks as not to be sent (`NonSendableStampInterface`). One of them, the `AckStamp` a worker adds
  to every message it handles, holds a closure, which igbinary cannot serialize, so every re-send of a
  received message - Symfony's retry, and the send to a NATS failure transport - threw "Serialization of
  'Closure' is not allowed". The exception stopped the worker before it rejected the message, JetStream
  redelivered it after `ack_wait`, and the next worker failed the same way, running the handler again each
  time. `encode()` now leaves those stamps out before serializing, as Symfony's own serializers do, for
  every serializer extending `AbstractEnveloperSerializer`.
- **With `retry_handler: nats`, Symfony's retry no longer multiplies the deliveries (#47).** Symfony's retry
  listener runs for every transport with a retry strategy, and FrameworkBundle gives each one a strategy
  (`max_retries: 3` by default). It re-sent a failed message as a copy while the transport NAKed the original
  for NATS to redeliver, so each failure was retried twice, and every copy did the same again: one message
  that kept failing ran its handler 120 times with `max_deliver: 3`. In nats mode the transport no longer
  publishes that copy, so Symfony's retry strategy (`max_retries`, `delay`, `multiplier`) is ignored and
  NATS redelivers alone, bounded by `max_deliver`. The README now explains how nats mode and Symfony's retry
  and failure transport combine.

## [5.2.0] - 2026-10-03

A **minor** release. It fixes a transport instance that, once its connection was lost, failed every later
operation until the process restarted (#49), and adds the `ping_after_idle` option that checks a connection
which sat idle, or just failed an operation, before it is used. The public PHP API is additive only: a new
`TransportOption::PING_AFTER_IDLE` case and the `pingAfterIdleSeconds()` and `connectionTimeoutSeconds()`
accessors on `NatsTransportConfiguration`. Nothing released in 5.1.0 was renamed or removed, and the PHP
(`^8.2`), Symfony (`^7.2 || ^8`) and `idct/php-nats-jetstream-client` (`^2.4`) requirements are
unchanged; client 2.10.1, released alongside, fixes the client side of the same problem and is recommended.

### Added
- **`ping_after_idle` option (seconds, default `30`; `0` turns it off) (#49).** An operation that finds
  the connection unused for longer than this first checks it with one PING, answered within
  `connection_timeout`, and runs on a new connection if the server does not answer. A server drops a
  client that stops answering its pings, which a PHP process does whenever it is outside a transport call,
  and load balancers and NAT gateways drop idle connections too, none of which the client notices until it
  writes: without the check, the first message after a quiet period was the one that failed. The same
  check follows an operation that failed on the connection other than with a JetStream reply: the client
  can keep reporting a dead connection Open (clients before 2.10 after a failed write, 2.10 after the
  server's fatal `-ERR`), which a re-dial waiting for Closed would never notice, and a connection that
  silently stopped delivering only times out. `keepalive()` is never checked, since Symfony calls it from
  a signal handler. `NatsTransportConfiguration` gains `pingAfterIdleSeconds()` and
  `connectionTimeoutSeconds()`.

### Fixed
- **A lost connection is re-established by the next operation instead of failing every operation until
  the process restarts (#49).** The client runs with reconnect off, so once a connection is lost - the
  server restarted, the network dropped it, or the server closed it because the client stopped answering
  its pings, which a PHP process idle for a few minutes between requests does - the client stays in its
  terminal Closed state and refuses every request. The transport only dialled when it had never connected,
  and nothing in Symfony calls `close()`, so a process that sends for longer than one request (a web app in
  worker mode, a daemon, a handler that dispatches) failed every operation from then on. A Closed client is
  now dialled again by the next operation; the operation that ran into the lost connection still fails.
  With `auto_setup`, the new connection is verified in the same call that dials it rather than the next
  one, and a dial that fails surfaces as the client's `ConnectionException` instead of being wrapped as
  "Failed to setup NATS stream", the first dial included.

## [5.1.0] - 2026-08-09

A **minor** release. It adds fifteen new DSN options and the `auto_setup` provisioning mode, and fixes
several ways the stream and consumer update paths could damage or fail against an existing deployment.
The public PHP API is additive only: new `TransportOption` cases, new accessors on
`NatsTransportConfiguration`, a new `StreamCompression` enum and a new `TypeCoercion::boolValue()`.
Nothing released in 5.0.0 was renamed or removed, and the PHP (`^8.2`), Symfony (`^7.2 || ^8`) and
`idct/php-nats-jetstream-client` (`^2.4`) requirements are unchanged.

### Added
- **New stream configuration DSN options** exposing JetStream stream settings the underlying client
  already supports but the transport did not surface: `stream_retention` (`limits`|`interest`|`workqueue`),
  `stream_discard` (`old`|`new`), `stream_duplicate_window` (seconds), `stream_max_message_size` (bytes),
  `stream_max_consumers`, `stream_compression` (`none`|`s2`), `stream_description`, and the access-policy
  flags `stream_deny_delete`, `stream_deny_purge`, `stream_allow_direct`, `stream_allow_rollup_headers`.
  Enum-backed options are validated with clear errors (like `retry_handler`); numeric options reuse the
  existing validators and require a positive integer, since `null` already means unlimited.
- **New consumer configuration DSN options**: `max_ack_pending`, `inactive_threshold` (seconds), and
  `replay_policy` (`instant`|`original`).
- **`auto_setup` option**, named after the Symfony AMQP transport's option of the same name. When
  enabled, the transport provisions the stream and consumer once, lazily, on the first `send()`/`get()`
  instead of requiring `messenger:setup-transports`. Unlike the AMQP transport, it defaults to `false`,
  which preserves existing behavior.
- **`stream_retention` is written only at stream creation.** NATS rejects changing the retention policy
  of an existing stream, so on the update path the transport preserves the live server value and a
  changed `stream_retention` is ignored until the stream is recreated. (`stream_storage` already behaved
  this way before this release.)
- **Build-time validation that `stream_duplicate_window` does not exceed a finite `stream_max_age`**,
  with a clear error instead of an opaque server rejection at setup time.
- **Consistent naming for the new stream accessors on `NatsTransportConfiguration`.** The stream-scoped
  getters carry the `stream` prefix every other stream accessor on the class already uses:
  `streamRetention()`, `streamDiscard()`, `streamDuplicateWindowSeconds()`, `streamCompression()`,
  `streamDenyDelete()`, `streamDenyPurge()`, `streamAllowDirect()` and `streamAllowRollupHeaders()`.
  The consumer-scoped getters (`maxAckPending()`, `inactiveThresholdMs()`, `replayPolicy()`) keep their
  unprefixed names, matching `consumer()`, `ackWaitMs()` and `maxDeliver()`. None of these names have
  been released.
- **`StreamCompression` enum** (`none`|`s2`) backing the `stream_compression` option, so the allowed
  values have one authoritative definition instead of an allowlist repeated in the validator, the
  accessor docblock and the README. `NatsTransportConfiguration::streamCompression()` returns the enum,
  matching how `stream_retention`, `stream_discard` and `replay_policy` are exposed.
- **`TypeCoercion::boolValue()`** centralizing the mixed to bool casting policy previously inlined in the
  configuration builder.

### Fixed
- **Tightened validation of the new numeric options.** `stream_max_message_size` and
  `stream_duplicate_window` now require a positive integer instead of a non-negative one: they default
  to `null`, which already means "unlimited" / "server default", so an explicit `0` only looked like the
  option had been ignored (NATS reads `max_msg_size: 0` as unlimited and replaces a `0` duplicate window
  with its own 2-minute default). `stream_max_message_size` is additionally capped at `2147483647`,
  since the server stores it as a 32-bit integer and a larger value failed inside `setup()` with a raw
  Go unmarshal error. `stream_description` is checked against the server's 4096-character limit.
- **The stream update path clamps an inherited de-duplication window to a lowered `stream_max_age`.**
  NATS rejects a stream whose `duplicate_window` exceeds a finite `max_age`, and the window is normally
  inherited from the live stream, so lowering `stream_max_age` on a stream sitting on the server's
  2-minute default window failed at setup with "duplicates window can not be larger then max age". The
  build-time check cannot catch this, since it only compares options the caller supplied. The update
  payload now clamps the window the same way the server does when a stream is created.
- **`auto_setup` now re-provisions when JetStream reports the stream or consumer as missing.** The
  one-shot flag was latched for the lifetime of the transport object, so a consumer that NATS removed
  after `inactive_threshold` left the worker unable to pull. A missing resource now triggers one
  re-provisioning attempt and a single retry, and `close()` clears the flag so a reopened connection
  verifies provisioning again. The statuses that count as "missing" are 404, 409 and **503**: a deleted
  durable leaves nothing subscribed to answer the pull, which surfaces as 503, not 404. Without
  `auto_setup` the historical contract is unchanged: 404 and 408 read as an empty queue and everything
  else propagates.
  Note that a replacement consumer starts from `deliver_policy=all`, so it redelivers everything the
  stream still retains, including acknowledged messages under the default `limits` retention. See the
  warning in the README: this is inherent to losing a durable consumer, not to `auto_setup`.
- **`stream_deny_delete` and `stream_deny_purge` are never cancelled on an existing stream.** NATS can
  turn both on but never off, so a stream that already denies deletes or purges would fail every
  `setup()` once the option was present as `false` - which is the value the README itself shows. The
  update path now keeps the server's value for those two. `stream_allow_direct` and
  `stream_allow_rollup_headers` were measured to be freely mutable and stay changeable.
- **The four tri-state stream flags reject unrecognized values.** `stream_deny_delete=maybe` used to
  coerce to `false` and be written to the server as a deliberate instruction; it is now a configuration
  error, consistent with the enum-backed options.
- **`replay_policy` no longer breaks `setup()` on an existing durable consumer.** NATS refuses to change
  a consumer's replay policy, in both directions, so adding `replay_policy` to the DSN of a running
  deployment made every `setup()` (and, with `auto_setup`, every `send()`/`get()`) fail with "replay
  policy can not be updated", unrecoverably. Removing the option again did not help: the omitted field
  reads as a change back to the server default and is rejected just the same. The transport now looks
  the consumer up and always writes its existing replay policy, applying the configured value only to a
  consumer that does not exist yet.
- **`stream_max_consumers` and `stream_max_message_size` no longer clobber an existing stream.** The
  update payload used to write `max_consumers` and `max_msg_size` unconditionally, falling back to the
  unlimited sentinel `-1` when the options were unset. Neither field was written at all before these
  options existed, so both are fields an operator may have set out of band on a live stream. For
  `max_consumers` the consequence was severe: NATS up to and including 2.11 rejects any change to it, so
  `messenger:setup-transports` failed permanently for anyone whose stream had a consumer limit, and on
  2.12 and newer the limit was silently cleared. `max_msg_size` is mutable everywhere, so it failed
  quietly instead: a stream capped at 1 MiB would be reset to unlimited on the next `setup()` with
  nothing in the output to say so. Both are now written only when the corresponding option is
  configured; otherwise the live server value is preserved.

## [5.0.0] - 2026-06-17

This is a **major** release. It is backward-incompatible for two reasons even though the transport's own
PHP API is largely additive: the required `idct/php-nats-jetstream-client` constraint moves from `^1` to
`^2.4` (a major dependency upgrade), and the internal `TypeCoercionTrait` was replaced by a `TypeCoercion`
final class. There are also two minor validation/behavior changes (see Fixed): `stream_max_age` now
rejects fractional values, and DSN/option credentials are no longer trimmed. PHP (`^8.2`) and Symfony
(`^7.2 || ^8`) requirements are unchanged from 4.0.0.

### Changed
- **Readability: removed two small internal duplications (BC-safe, no behavior change)** - the
  `{topic}.delayed.>` wildcard subject now has a single `delayedSubjectPattern()` definition shared by
  the add ({@see buildDesiredSubjects}) and drop ({@see buildUpdatedStreamConfiguration}) sides so they
  cannot drift, and `AbstractEnveloperSerializer::decode()` folds its missing-`body` guard into the
  null/non-string/empty check via `?? null` (one gate instead of two with the same message). A deep
  readability review (with adversarial verification) considered several other extractions and rejected
  them as taste-driven churn in this deliberately-verbose, multiply-reviewed code.
- **Collapsed redundant double-guards in DSN/option validation** - `toNumber()` now gates solely on
  `is_numeric()` (which already rejects every non-numeric type), and `parseDsn()` validates the
  `parse_url()` result and the missing host in a single throw. The previous split guards threw the same
  message for the same inputs, so each masked the other - leaving undetectable (equivalent) mutants.
  No behavior change; mutation Covered MSI rises from ~99% to 100%.
- **NATS client upgraded to `idct/php-nats-jetstream-client` `^2.4`** (from `^1`). The v2 client is a
  major release (Object Store / Services / custom-transport breaking changes) but none of those touch
  this bridge's API surface; every method this transport uses changed only by gaining optional trailing
  parameters. The unit suite and PHPStan (level max) stay green.
- **Unified message publishing** - `send()` now publishes both plain and header-carrying (including
  scheduled/delayed) messages through `JetStreamContext::publish()` instead of dropping to the low-level
  `requestWithHeaders()` for header messages. Header/scheduled publishes therefore gain the client's
  built-in transient-503 ("no responders") retry and consistent `JetStreamException` error reporting.
  The hand-rolled `assertJetStreamPublishSucceeded()` validator was removed.
- **`getMessageCount()`** now returns `num_ack_pending + num_pending` (in-flight **plus** waiting)
  instead of `max(...)`, which undercounted whenever both coexisted.
- **`declare(strict_types=1)`** is now declared in every `src/` file.
- **`TypeCoercionTrait` replaced by a `final class TypeCoercion`** with pure `public static`
  `intValue()` / `floatValue()` / `stringValue()` helpers. The mixed→scalar coercion policy is now a
  standalone, independently testable unit (own unit tests plus a functional DSN-coercion scenario)
  instead of a trait mixed into three classes. No behavior change.
- **Deterministic existing-stream detection in `setup()`** - when stream creation fails, the transport
  now queries JetStream stream info (`404` ⇒ absent) to decide whether to update, instead of matching
  server-specific `"already in use"` / `"already exists"` error strings. This removes the brittle
  message parsing and collapses the previous two stream-info lookups into one (the fetched config is
  reused for the update).
- **Typed stream/consumer configuration in `setup()`** - the stream and durable consumer are now built
  with the v2 client's fluent `StreamConfiguration` / `ConsumerConfiguration` builders and created via
  `addStream()` / `addConsumer()`, replacing hand-assembled option arrays. A single `StreamConfiguration`
  is the source for both the create and update paths (the latter via `toArray()`), and `maxAge()` handles
  the seconds→nanoseconds conversion. No behavior change.

### Added
- **Daily scheduled mutation-testing workflow and Dependabot** - `.github/workflows/mutation.yml` re-runs
  Infection daily (03:17 UTC) and on demand (`workflow_dispatch`) against the default branch, a safety net
  beyond the per-push/PR mutation step in the main CI; it uploads `infection.log` on failure.
  `.github/dependabot.yml` keeps the root and `tests/functional` Composer dependencies and the GitHub
  Actions up to date on a weekly cadence (minor/patch bumps grouped to cut PR noise).
- **Hardened large-message, multi-consumer, and scheduled-message coverage** - new unit tests for
  large (1 MiB) payload round-tripping on both the send and receive paths
  (`testSendPublishesLargePayloadWithoutTruncation`, `testGetDecodesLargePayloadWithoutTruncation`),
  shared-durable-consumer routing (`testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer`),
  and never-early scheduling (`testSendDelayedMessageNeverSchedulesBeforeRequestedDelay`). New Behat
  feature `nats_large_messages.feature` round-trips 128 KB / 64 KB messages through one consumer and
  load-balances them across two, and `nats_delayed.feature` gains a scenario asserting a delayed message
  is not visible to the consumer before its scheduled time. The send command gained a `--size` option.
  Further hardening: a 5-consumer / 100-message shared-consumer load-balancing scenario
  (`nats_consumer.feature`), larger delayed batches and delayed-message load balancing across 3 consumers
  (`nats_delayed.feature`), and a unit test for a far-future (1-hour) delay
  (`testSendDelayedMessageWithLargeDelaySchedulesFarInTheFuture`). The CI "Run functional tests" step
  timeout was raised from 10 to 25 minutes (and the job cap from 20 to 40) to accommodate the fuller
  matrix; the new delayed scenarios use count-limited consumers so they exit promptly after the delay
  rather than waiting out a fixed time limit.
- **README PHP examples are syntax-checked in CI** - `ReadmeExamplesTest` lints every fenced ` ```php `
  block in the README (and pins their count), so a snippet that stops being valid PHP fails the build.
  This complements the existing tests that exercise the README's DSN, option, and serializer examples.
- **`CloseableTransportInterface` support** - the transport now implements `close()`, which disconnects
  the NATS client and resets the lazy connection state so resources are released on demand (when the
  application calls it: Symfony itself never calls `close()`, not even on worker shutdown). It is a no-op
  when no connection was opened, and the transport reconnects lazily on the next operation.
- **`KeepaliveReceiverInterface` support** - the transport now implements Symfony Messenger's
  `keepalive()`, sending an in-progress (`+WPI`) acknowledgement so a long-running handler resets the
  JetStream redelivery timer instead of losing its message to `ack_wait` expiry. NATS resets to the
  consumer's configured `ack_wait`, so the advisory `$seconds` hint is not forwarded.
- **Mutation testing with Infection** - added `infection/infection` (dev), an `infection.json5` config
  (floors: `minMsi` 90 / `minCoveredMsi` 95), a `composer test:mutation` script, and a CI step. The suite
  currently scores 100% covered MSI with 100% mutation code coverage.
- **Expanded unit coverage (~99.6% statements) and functional coverage for the new features** - added
  unit tests for previously-uncovered branches (non-scalar option coercion, too-short DSN path,
  integer/uppercase boolean coercion, update-path `max_age` conversion, non-array server subjects) and
  Behat scenarios for `ack_sync` (synchronous acknowledgement) and `max_deliver` (NATS bounds redelivery
  of a poison message).
- **NATS-native redelivery tuning for `retry_handler: nats`** - new options `nak_delay` (seconds to
  delay a NAK, default 0), `ack_wait` (seconds before JetStream redelivers an unacked message),
  `max_deliver` (cap on redeliveries; **prevents a poison message redelivering forever**), and `backoff`
  (per-attempt delay schedule in seconds). NAKs use the client's `nakWithDelay()` when a delay is set,
  and the durable consumer is created with the configured `ack_wait` / `max_deliver` / `backoff`. All
  default to the previous behavior (immediate NAK, server-default ack-wait, unlimited redeliveries).
- **Up-front validation that `max_deliver` exceeds the `backoff` length** - when both options are set,
  the builder now rejects a `max_deliver` that is not strictly greater than the number of `backoff`
  entries (NATS requires room for at least one delivery beyond the backoff schedule), turning an opaque
  server-side consumer-creation failure into a clear configuration error.
- **`ack_sync` option (opt-in double-ack)** - when enabled, `ack()` uses the v2 client's `ackSync()` and
  waits for server confirmation of each acknowledgement, so a dropped ACK cannot silently cause
  redelivery. Defaults to `false` (fire-and-forget, lower latency).
- **`CLAUDE.md`, `HUMANS.md`, `STRUCTURE.md`** - agent guidance, human onboarding, and an architecture/
  layout reference, respectively.

### Changed
- **Simplified DSN path validation** (internal) - removed the redundant `MIN_PATH_LENGTH` guard, which
  was fully subsumed by the stream/topic segment check. A path that supplies a stream but no topic
  (e.g. `/a`) now reports the more accurate "must contain both stream name and topic name" error instead
  of "Stream name not provided." The two numeric validators now share a symmetric `$integerOnly = false`
  signature.
- **`stream_max_age` now rejects non-integer values** instead of silently truncating them (e.g. `2.5`
  was accepted and coerced to `2`). The option is integer seconds; fractional values now raise a clear
  validation error, consistent with the other integer-only stream limits.
- **Removed the redundant `floatOption()` accessor** (internal, no behavior change) - its two callers
  now read the option directly through `TypeCoercion::secondsToMs()`, unifying all the seconds→ms
  accessors on one idiom and removing a double-coercion.
- **De-duplicated option conversion logic** (internal, no behavior change) - added
  `TypeCoercion::secondsToMs()` (the single home for the seconds→milliseconds rounding rule, previously
  copy-pasted across five call sites) and a private `nullableIntOption()` accessor in
  `NatsTransportConfiguration` (the four optional stream-limit / `max_deliver` getters now share one
  null-passthrough definition).

### Fixed
- **README accuracy pass** - updated the stale "~99% covered MSI" figure to 100% (matching the badge),
  rewrote the "Publish Response Validation" section to describe the current client-side `publish()` ack
  validation (the old hand-rolled JSON/"header-aware request path" wording was removed long ago), and
  fixed the Quick Start send example to inject `MessageBusInterface` (Symfony does not autowire the
  concrete `MessageBus`). A full audit confirmed every other example, option default, "Tested by"
  method, Behat scenario, and DSN data-provider case in the README still matches the code.
- **Scheduled (delayed) messages are never delivered before the requested delay** (#37) - the
  `DelayStamp` delay maps onto a whole-second NATS `@at` schedule that previously *truncated* the
  sub-second part, so a small delay could fire immediately and any delay could arrive up to ~1s early.
  `send()` now rounds the delivery time **up** to the next whole second, so the message is never
  delivered before the requested delay elapses (at most ~1s late). See the README for the
  second-resolution caveat.
- **A failed NAK/TERM no longer masks the original decode error in `get()`** (#34) - when a delivered
  message fails to deserialize, the transport still rejects it, but a secondary failure while
  acknowledging (e.g. a dropped connection) can no longer replace the decode exception that propagates.
  The decode error is the root cause an operator needs, so it stays the one that escapes `get()`.
- **`setup()` removes an orphaned `{topic}.delayed.>` subject when `scheduled_messages` is disabled**
  (#35) - the stream-update subject merge previously only ever added subjects, so a stream that once had
  scheduling enabled kept the transport-managed delayed subject forever. The update now drops that
  specific subject when scheduling is off (operator-added subjects are preserved), mirroring the
  `allow_msg_schedules=false` clearing.
- **Clarified `getMessageCount()`'s fallback semantics** (#36) - documented that the stream-level
  fallback (used when consumer info is unavailable) is a loose upper bound, not an exact backlog: under
  the default limits retention policy it counts already-acknowledged-but-retained messages. The accurate
  consumer-info path (`num_ack_pending + num_pending`) is unaffected.
- **README/docs accuracy** - corrected the Architecture "serialization (igbinary)" bullet to describe the
  pluggable `SerializerInterface`, listed the full set of implemented Messenger interfaces, fixed the
  `docs/TESTS.md` mutation thresholds (95/98 -> 90/95), and refreshed the test count and coverage badge.
- **DSN/option credentials are no longer trimmed** - leading or trailing whitespace in a username or
  password is significant and was previously stripped (corrupting the credential). Credential resolution
  now maps only null/empty to null without trimming; TLS file paths are still trimmed.
- **Disabling `scheduled_messages` clears `allow_msg_schedules` on an existing stream** - the `setup()`
  update path now writes the flag explicitly (false when disabling) instead of preserving the server's
  previous `true`. The field is still omitted entirely when scheduling is off and the stream never had
  it, so nothing unknown is sent to a server older than NATS 2.12.
- **`setup()` no longer silently downscales an existing stream's replica count** - on the update path
  the transport now preserves the server's `num_replicas` unless `stream_replicas` is explicitly
  configured (mirroring the existing `storage` preservation). Previously a stream created with, say,
  3 replicas in a cluster was reset to 1 whenever `setup()` ran without the option set, silently
  eliminating its high-availability/durability.
- **DSN credentials are decoded with `rawurldecode()` instead of `urldecode()`** - a literal `+` in a
  username/password supplied via the DSN was being turned into a space (form-encoding semantics),
  corrupting the credential and breaking authentication. Percent-escapes (`%40` → `@`, `%2B` → `+`)
  still decode correctly, now matching RFC 3986 userinfo semantics and the underlying NATS client.
- **README handler example no longer references a removed interface** - the "Handle Messages" example
  used `Symfony\Component\Messenger\Handler\MessageHandlerInterface`, removed in Symfony 7.0 (the
  package requires `^7.2 || ^8.0`), so copying it caused a fatal "interface not found". It now uses
  the `#[AsMessageHandler]` attribute.
- **`get()` LogicException message** now names the actual stamp (`TransportMessageIdStamp`) instead of
  the unrelated `ReceivedStamp`; the README `connection_timeout` docs now state it sets the connection
  (dial) timeout, not a per-operation socket I/O timeout.
- **README/comment accuracy (review sweep)** - corrected three stale `Tested by:` references in the
  README that survived the earlier docs sweep (`testSendUsesRequestWithHeadersWhenHeadersArePresent` →
  `…UsesPublish…`; two `testGetMessageCountReturnsAckPendingWhenHigherThanPending` → `…SumsAckPendingAndPending`),
  the Infection threshold paragraph (95/98 → 90/95) and the deprecated `composer install --dev` command;
  reworded the `NatsTransportFactory` class docblock (the igbinary auto-default never triggers via the
  factory, only on direct construction) and the `get()` docblock ("HTTP 404/408" → "JetStream status 404/408").
- **Empty-payload messages no longer poison-loop** - `get()` now sends TERM for a delivered message
  with an empty payload (which can never decode into an envelope) instead of silently skipping it.
  Skipping left the message unacknowledged, so JetStream redelivered it every `ack_wait` indefinitely;
  TERM stops redelivery regardless of the configured retry handler. A message without a reply (ack)
  subject is still skipped, since it cannot be acknowledged at all.
- **README serializer default clarified** - documented that under the Symfony framework the transport
  factory always receives Symfony's resolved serializer (the framework default is the native
  `PhpSerializer`), so the transport's built-in igbinary auto-selection only applies to direct
  instantiation; to use igbinary under the framework, set the transport's `serializer:` key explicitly.
- **Actionable error for `scheduled_messages` on NATS < 2.12** - when the connected server is too old
  for `allow_msg_schedules`, `setup()` now catches the client's typed `UnsupportedFeatureException` and
  fails with a clear message ("The 'scheduled_messages' option requires NATS Server >= 2.12, but the
  connected server reports …. Disable scheduled_messages or upgrade NATS.") instead of a generic wrapped
  error.
- **Publish acknowledgements always fail closed** - the previous header-publish path silently accepted
  an empty/non-JSON JetStream ack; publishing through `JetStreamContext::publish()` rejects empty,
  malformed, or error acks consistently for all messages.
- **`get()` skips messages without a reply (ack) subject** instead of yielding an envelope with an
  unusable transport message id that would later fail at ack/reject time.
- **`setup()` can now relax or clear stream limits** - on update, unset `stream_max_age` /
  `stream_max_bytes` / `stream_max_messages` / `stream_max_messages_per_subject` options reset to
  JetStream's "unlimited" sentinels instead of preserving the previous server-side value.
- **`getMessageCount()` catches `\Throwable`** (not just `\Exception`), honouring its documented
  "returns 0 if both lookups fail" contract for `\Error`-type failures surfaced by awaited futures.
- **README accuracy** - corrected the coverage badge (`95.97%` → `99.56%`) and the test-count claim
  (`102` → `248` unit tests), and removed a non-existent `delay` option from the Multi-Subject Streams
  example (there is no `delay` transport option; the value was silently ignored).
- **Documentation** - refreshed the stale `tests/functional/README.md` (removed dead benchmark-doc
  links and replaced the outdated "three scenarios" list with the full feature-file table) and removed
  the non-existent `delay` option from the builder tests and `docs/TESTS.md`.
- **Test suite** - migrated the last doc-comment `@dataProvider` to the `#[DataProvider]` attribute
  (PHPUnit 12-ready), hardened the Behat consumed-message check to use the deterministic marker-file
  count as the primary signal, and added unit coverage for the lazy-connect path.

### Security
- **PhpSerializer fallback now warns loudly** - when `ext-igbinary` is missing and no serializer is
  configured, the transport emits an `E_USER_WARNING` (previously a quiet `E_USER_NOTICE`) explaining
  that the `PhpSerializer` fallback uses native `unserialize()` and carries the same object-injection
  risk as igbinary. The README security section now documents this explicitly.

## [4.0.0]

### Added
- **IDCT NATS JetStream Client** - Replaced `basis-company/nats` with `idct/php-nats-jetstream-client` (`^1`) for amphp-based coroutine support, active maintenance, and access to newer NATS features.
- **Configurable retry handler** - New `retry_handler` option (`symfony` or `nats`) controls failure behavior. `symfony` (default) sends TERM; `nats` sends NAK for NATS-managed redelivery.
- **Scheduled / delayed messages** - `scheduled_messages` option enables Symfony `DelayStamp` support via NATS scheduled message headers (`Nats-Schedule`, `Nats-Schedule-Target`). Requires NATS >= 2.12.
- **Multi-subject streams** - Multiple transports can share a single NATS stream with different subjects. Setup merges subjects without duplicating or overwriting existing ones.
- **Stream storage backend** - `stream_storage` option (`file` or `memory`) controls JetStream stream storage type. Existing streams preserve their original storage backend on update.
- **Stream max messages** - `stream_max_messages` option limits total messages stored in the stream (maps to NATS `max_msgs`).
- **Stream max messages per subject** - `stream_max_messages_per_subject` option limits messages retained per individual subject (maps to NATS `max_msgs_per_subject`).
- **Stream max bytes** - `stream_max_bytes` option limits total storage size of the stream.
- **TLS and mTLS support** - Full TLS configuration options including `tls_required`, `tls_handshake_first`, `tls_ca_file`, `tls_cert_file`, `tls_key_file`, `tls_key_passphrase`, `tls_peer_name`, and `tls_verify_peer`.
- **Publish response validation** - JetStream publish acknowledgements are parsed and validated; protocol errors fail closed instead of being silently accepted.
- **Stream-exists detection hardening** - Setup prefers explicit NATS conflict messages for existing-stream detection; ambiguous 400 responses trigger a stream-existence verification before updating.
- **Comprehensive functional test suite** - Behat-based functional tests covering message flow, batching, TLS, mTLS, NAK/TERM retry handlers, delayed messages, stream limits, multi-subject streams, and consumer strategies.
- **PHPStan level max** - Static analysis at maximum strictness level.
- **Edge case test coverage** - Added tests for: decode failure with NAK handler, multiple message batching, consumer creation errors, TLS DSN constructor, negative delay stamps, stream update failures, batching config flow-through, partial batch consumption, stream eviction enforcement, consumer name verification via JetStream API.
- **Builder validation tests** - Added tests for: negative batching, non-integer batching float, negative connection timeout, non-numeric connection timeout, zero/negative/non-numeric max_batch_timeout, negative/non-integer stream_replicas, non-numeric stream_max_age, array batching, malformed DSN, missing host DSN, dotted topic names, connection timeout propagation to NatsClient.
- **Factory DSN edge cases** - Added tests for: default port parsing, no-auth DSN, query parameter parsing, HTTP scheme rejection.

### Changed
- **Default failure behavior** - `reject()` now sends TERM (previously ACK in v3). This is a **breaking change**; use `retry_handler: nats` to restore NAK-based redelivery.
- **PHP requirement** - Minimum PHP version raised to 8.2.
- **PHPUnit** - Upgraded to PHPUnit 11.
- **Symfony compatibility** - Supports Symfony ^7.2 and ^8.0.

### Fixed
- **`stream_max_messages` not applied** - The option was previously ignored during stream creation; now correctly maps to NATS `max_msgs`.

## [3.x] - Previous releases

Initial Symfony Messenger NATS JetStream bridge using `basis-company/nats` client library.
