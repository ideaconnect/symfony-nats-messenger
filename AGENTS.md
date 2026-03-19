# Project Guide

## Overview

Symfony Messenger transport for NATS JetStream. PHP 8.2+, Symfony ^7.2 / ^8.0.
Uses `idct/php-nats-jetstream-client` (amphp-based coroutines).

## Installation

```bash
composer require idct/symfony-nats-messenger
```

Development setup:
```bash
composer install
composer test              # PHPStan + fast unit tests (run after every change)
```

## Application Structure

```
src/
├── NatsTransport.php                          # TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
├── NatsTransportFactory.php                   # TransportFactoryInterface — DSN parsing, transport creation
├── TypeCoercionTrait.php                      # Safe scalar type coercion helpers
├── Options/
│   ├── NatsTransportConfiguration.php         # Immutable readonly config value object
│   ├── NatsTransportConfigurationBuilder.php  # DSN + options parsing, validation, builds Configuration
│   ├── RetryHandler.php                       # Enum: SYMFONY (TERM) | NATS (NAK)
│   └── TransportOption.php                    # Enum: all configurable option keys
└── Serializer/
    ├── AbstractEnveloperSerializer.php         # Base serializer with encode/decode envelope wrapping
    └── IgbinarySerializer.php                  # Default serializer (falls back to PhpSerializer)
```

```
tests/
├── bootstrap.php
├── unit/                                       # PHPUnit 11 — fast, no NATS required
│   ├── NatsTransportTest.php                   # Transport core: setup, send, get, ack, reject, retry
│   ├── NatsTransportFactoryTest.php            # Factory: DSN scheme detection, transport creation
│   ├── Options/
│   │   ├── NatsTransportConfigurationBuilderTest.php  # Builder validation, DSN parsing, option merging
│   │   └── NatsTransportConfigurationTest.php         # Config accessors, type coercion
│   └── Serializer/
│       ├── AbstractEnveloperSerializerTest.php  # Encode/decode round-trips, error cases
│       └── IgbinarySerializerTest.php           # Igbinary serialize/deserialize
├── functional/                                 # Behat — requires running NATS server
│   ├── features/
│   │   ├── nats_setup.feature                  # Stream creation, update, multi-subject, message flow
│   │   ├── nats_stream_limits.feature          # max_bytes, max_msgs, max_msgs_per_subject
│   │   ├── nats_consumer.feature               # Custom consumer names
│   │   ├── nats_batching.feature               # Batch consumption
│   │   ├── nats_nak.feature                    # NAK retry handler
│   │   ├── nats_term.feature                   # TERM failure handler
│   │   ├── nats_delayed.feature                # Scheduled/delayed messages
│   │   ├── nats_tls.feature                    # TLS connections
│   │   └── nats_mtls.feature                   # mTLS with client certificates
│   └── tests/Behat/NatsSetupContext.php        # All step definitions
└── nats/                                       # Docker Compose + NATS configs for testing
    ├── docker-compose.yaml
    ├── nats.conf / nats-tls.conf / nats-mtls.conf
    └── certs/                                  # Test TLS certificates
```

## Features

- **DSN schemes**: `nats-jetstream://` and `nats-jetstream+tls://`
- **Stream management**: create, update existing (subject merging, config preservation)
- **Retention policies**: `stream_max_age`, `stream_max_bytes`, `stream_max_messages`, `stream_max_messages_per_subject`
- **Storage backends**: `file` or `memory` via `stream_storage`
- **Replication**: `stream_replicas` for HA
- **Consumer strategies**: shared (batching=1) or independent consumers
- **Batching**: configurable `batching` count and `max_batch_timeout`
- **Retry handling**: `retry_handler` — `symfony` (TERM, default) or `nats` (NAK)
- **Scheduled messages**: `scheduled_messages` flag, `DelayStamp` → NATS schedule headers
- **TLS/mTLS**: full TLS config options including client certificates
- **Authentication**: username/password, token, JWT, NKey
- **Serialization**: igbinary (default with fallback) or custom via `AbstractEnveloperSerializer`
- **Publish validation**: JetStream publish responses are parsed and validated

## Testing

### After every code change
```bash
composer test                    # PHPStan (level max) + fast unit tests
```

### Unit tests with coverage
```bash
composer test:unit               # Coverage report → clover.xml + coverage/
composer coverage:check          # Verify ≥ 90% statement coverage
```

### Functional tests (require Docker)
```bash
composer test:functional:setup   # Install Behat dependencies (first time)
composer nats:start              # Start NATS in Docker
composer test:functional         # Run Behat scenarios
composer nats:stop               # Stop NATS
```

### Key testing patterns
- Unit tests use `TestableNatsTransport` (overrides `connect()` to skip live NATS)
- `RuntimeTestableNatsTransport` allows injecting mock `JetStreamContext` and `NatsClient` via setters
- Mock `Future::complete()` / `Future::error()` for async return values
- `self::callback()` for complex assertion on method arguments (e.g., stream options arrays)

## Documentation Maintenance

### Test Coverage Map (`docs/TESTS.md`)
- Keep `docs/TESTS.md` up to date whenever tests are added, removed, or renamed.
- When a new feature is implemented, add it to the appropriate table with the tests that cover it.
- When a test method is renamed or deleted, update the corresponding entry.

### Changelog (`docs/CHANGELOG.md`)
- Every release must have an entry in `docs/CHANGELOG.md`.
- Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.
- Add new items under `## [Unreleased]` as work progresses; move them to a versioned heading at release time.
- Categorize changes as Added, Changed, Fixed, Deprecated, Removed, or Security.

### PR Merge Documentation (`docs/PRs/`)
- When a PR is merged or its features are adapted, add a file to `docs/PRs/` named `PR-NNN-short-description.md`.
- Include: what the PR proposed, how the features were implemented, and which tests cover them.
