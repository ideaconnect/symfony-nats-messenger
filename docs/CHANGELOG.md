# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **NATS client upgraded to `idct/php-nats-jetstream-client` `^2.4`** (from `^1`). The v2 client is a
  major release (Object Store / Services / custom-transport breaking changes) but none of those touch
  this bridge's API surface; every method this transport uses changed only by gaining optional trailing
  parameters. The unit suite and PHPStan (level max) stay green.
- **Unified message publishing** — `send()` now publishes both plain and header-carrying (including
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
- **Deterministic existing-stream detection in `setup()`** — when stream creation fails, the transport
  now queries JetStream stream info (`404` ⇒ absent) to decide whether to update, instead of matching
  server-specific `"already in use"` / `"already exists"` error strings. This removes the brittle
  message parsing and collapses the previous two stream-info lookups into one (the fetched config is
  reused for the update).
- **Typed stream/consumer configuration in `setup()`** — the stream and durable consumer are now built
  with the v2 client's fluent `StreamConfiguration` / `ConsumerConfiguration` builders and created via
  `addStream()` / `addConsumer()`, replacing hand-assembled option arrays. A single `StreamConfiguration`
  is the source for both the create and update paths (the latter via `toArray()`), and `maxAge()` handles
  the seconds→nanoseconds conversion. No behavior change.

### Added
- **`ack_sync` option (opt-in double-ack)** — when enabled, `ack()` uses the v2 client's `ackSync()` and
  waits for server confirmation of each acknowledgement, so a dropped ACK cannot silently cause
  redelivery. Defaults to `false` (fire-and-forget, lower latency).
- **`CLAUDE.md`, `HUMANS.md`, `STRUCTURE.md`** — agent guidance, human onboarding, and an architecture/
  layout reference, respectively.

### Fixed
- **Actionable error for `scheduled_messages` on NATS < 2.12** — when the connected server is too old
  for `allow_msg_schedules`, `setup()` now catches the client's typed `UnsupportedFeatureException` and
  fails with a clear message ("The 'scheduled_messages' option requires NATS Server >= 2.12, but the
  connected server reports …. Disable scheduled_messages or upgrade NATS.") instead of a generic wrapped
  error.
- **Publish acknowledgements always fail closed** — the previous header-publish path silently accepted
  an empty/non-JSON JetStream ack; publishing through `JetStreamContext::publish()` rejects empty,
  malformed, or error acks consistently for all messages.
- **`get()` skips messages without a reply (ack) subject** instead of yielding an envelope with an
  unusable transport message id that would later fail at ack/reject time.
- **`setup()` can now relax or clear stream limits** — on update, unset `stream_max_age` /
  `stream_max_bytes` / `stream_max_messages` / `stream_max_messages_per_subject` options reset to
  JetStream's "unlimited" sentinels instead of preserving the previous server-side value.
- **`getMessageCount()` catches `\Throwable`** (not just `\Exception`), honouring its documented
  "returns 0 if both lookups fail" contract for `\Error`-type failures surfaced by awaited futures.
- **README accuracy** — corrected the coverage badge (`95.97%` → `98.06%`) and the test-count claim
  (`102` → `232` unit tests), and removed a non-existent `delay` option from the Multi-Subject Streams
  example (there is no `delay` transport option; the value was silently ignored).
- **Documentation** — refreshed the stale `tests/functional/README.md` (removed dead benchmark-doc
  links and replaced the outdated "three scenarios" list with the full feature-file table) and removed
  the non-existent `delay` option from the builder tests and `docs/TESTS.md`.
- **Test suite** — migrated the last doc-comment `@dataProvider` to the `#[DataProvider]` attribute
  (PHPUnit 12-ready), hardened the Behat consumed-message check to use the deterministic marker-file
  count as the primary signal, and added unit coverage for the lazy-connect path.

### Security
- **PhpSerializer fallback now warns loudly** — when `ext-igbinary` is missing and no serializer is
  configured, the transport emits an `E_USER_WARNING` (previously a quiet `E_USER_NOTICE`) explaining
  that the `PhpSerializer` fallback uses native `unserialize()` and carries the same object-injection
  risk as igbinary. The README security section now documents this explicitly.

## [4.0.0]

### Added
- **IDCT NATS JetStream Client** — Replaced `basis-company/nats` with `idct/php-nats-jetstream-client` (`^1`) for amphp-based coroutine support, active maintenance, and access to newer NATS features.
- **Configurable retry handler** — New `retry_handler` option (`symfony` or `nats`) controls failure behavior. `symfony` (default) sends TERM; `nats` sends NAK for NATS-managed redelivery.
- **Scheduled / delayed messages** — `scheduled_messages` option enables Symfony `DelayStamp` support via NATS scheduled message headers (`Nats-Schedule`, `Nats-Schedule-Target`). Requires NATS >= 2.12.
- **Multi-subject streams** — Multiple transports can share a single NATS stream with different subjects. Setup merges subjects without duplicating or overwriting existing ones.
- **Stream storage backend** — `stream_storage` option (`file` or `memory`) controls JetStream stream storage type. Existing streams preserve their original storage backend on update.
- **Stream max messages** — `stream_max_messages` option limits total messages stored in the stream (maps to NATS `max_msgs`).
- **Stream max messages per subject** — `stream_max_messages_per_subject` option limits messages retained per individual subject (maps to NATS `max_msgs_per_subject`).
- **Stream max bytes** — `stream_max_bytes` option limits total storage size of the stream.
- **TLS and mTLS support** — Full TLS configuration options including `tls_required`, `tls_handshake_first`, `tls_ca_file`, `tls_cert_file`, `tls_key_file`, `tls_key_passphrase`, `tls_peer_name`, and `tls_verify_peer`.
- **Publish response validation** — JetStream publish acknowledgements are parsed and validated; protocol errors fail closed instead of being silently accepted.
- **Stream-exists detection hardening** — Setup prefers explicit NATS conflict messages for existing-stream detection; ambiguous 400 responses trigger a stream-existence verification before updating.
- **Comprehensive functional test suite** — Behat-based functional tests covering message flow, batching, TLS, mTLS, NAK/TERM retry handlers, delayed messages, stream limits, multi-subject streams, and consumer strategies.
- **PHPStan level max** — Static analysis at maximum strictness level.
- **Edge case test coverage** — Added tests for: decode failure with NAK handler, multiple message batching, consumer creation errors, TLS DSN constructor, negative delay stamps, stream update failures, batching config flow-through, partial batch consumption, stream eviction enforcement, consumer name verification via JetStream API.
- **Builder validation tests** — Added tests for: negative batching, non-integer batching float, negative connection timeout, non-numeric connection timeout, zero/negative/non-numeric max_batch_timeout, negative/non-integer stream_replicas, non-numeric stream_max_age, array batching, malformed DSN, missing host DSN, dotted topic names, connection timeout propagation to NatsClient.
- **Factory DSN edge cases** — Added tests for: default port parsing, no-auth DSN, query parameter parsing, HTTP scheme rejection.

### Changed
- **Default failure behavior** — `reject()` now sends TERM (previously ACK in v3). This is a **breaking change**; use `retry_handler: nats` to restore NAK-based redelivery.
- **PHP requirement** — Minimum PHP version raised to 8.2.
- **PHPUnit** — Upgraded to PHPUnit 11.
- **Symfony compatibility** — Supports Symfony ^7.2 and ^8.0.

### Fixed
- **`stream_max_messages` not applied** — The option was previously ignored during stream creation; now correctly maps to NATS `max_msgs`.

## [3.x] - Previous releases

Initial Symfony Messenger NATS JetStream bridge using `basis-company/nats` client library.
