# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - Unreleased

### Added
- **IDCT NATS JetStream Client** — Replaced `basis-company/nats` with `idct/php-nats-jetstream-client` (`dev-main`) for amphp-based coroutine support, active maintenance, and access to newer NATS features.
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

### Changed
- **Default failure behavior** — `reject()` now sends TERM (previously ACK in v3). This is a **breaking change**; use `retry_handler: nats` to restore NAK-based redelivery.
- **PHP requirement** — Minimum PHP version raised to 8.2.
- **PHPUnit** — Upgraded to PHPUnit 11.
- **Symfony compatibility** — Supports Symfony ^7.2 and ^8.0.

### Fixed
- **`stream_max_messages` not applied** — The option was previously ignored during stream creation; now correctly maps to NATS `max_msgs`.

## [3.x] - Previous releases

Initial Symfony Messenger NATS JetStream bridge using `basis-company/nats` client library.
