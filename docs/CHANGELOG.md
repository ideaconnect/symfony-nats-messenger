# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  the NATS client and resets the lazy connection state so resources are released on demand (e.g. on
  worker shutdown). It is a no-op when no connection was opened, and the transport reconnects lazily on
  the next operation.
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
