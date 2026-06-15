# Project Structure

Architecture and layout reference for `idct/symfony-nats-messenger` — a Symfony Messenger
transport that bridges Symfony's message bus to [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream).

For day-to-day commands and conventions see [CLAUDE.md](CLAUDE.md) / [AGENTS.md](AGENTS.md).
For a human-friendly onboarding see [HUMANS.md](HUMANS.md).

## High-level picture

```
Symfony Messenger  ──dispatch──▶  NatsTransportFactory ──▶ NatsTransport ──▶ idct/php-nats-jetstream-client ──▶ NATS JetStream
   (Envelope)                       (DSN → instance)        (TransportInterface)        (amphp / Future)            (stream + pull consumer)
```

- **Symfony side:** the library implements `TransportInterface`, `MessageCountAwareInterface`,
  and `SetupableTransportInterface`, so it plugs into `messenger:consume`, `messenger:setup-transports`,
  and the failure/retry machinery like any first-party transport.
- **NATS side:** every operation goes through the async `idct/php-nats-jetstream-client` (`^2.4`,
  amphp-based). Calls return `Amp\Future`; the transport resolves them synchronously with `->await()`
  because Symfony Messenger's transport contract is blocking.

## Directory layout

```
src/
├── NatsTransport.php                          # Core transport: send/get/ack/reject/setup/getMessageCount
├── NatsTransportFactory.php                   # TransportFactoryInterface — DSN scheme detection + instantiation
├── TypeCoercion.php                           # Safe mixed → int/float/string coercion (final, static)
├── Options/
│   ├── NatsTransportConfiguration.php         # Immutable readonly value object of resolved settings
│   ├── NatsTransportConfigurationBuilder.php  # Parses DSN + options, validates, builds the NatsClient
│   ├── RetryHandler.php                        # Enum: SYMFONY (TERM) | NATS (NAK)
│   └── TransportOption.php                     # Enum of every recognized option key
└── Serializer/
    ├── AbstractEnveloperSerializer.php         # Base encode/decode envelope wrapping + validation
    └── IgbinarySerializer.php                  # Default serializer (igbinary), falls back to PhpSerializer

tests/
├── bootstrap.php
├── unit/                                       # PHPUnit 11 — fast, no live NATS required
│   ├── NatsTransportTest.php                   # send/get/ack/reject/setup/retry/count/scheduled
│   ├── NatsTransportFactoryTest.php            # scheme detection, transport creation
│   ├── Options/
│   │   ├── NatsTransportConfigurationBuilderTest.php  # DSN parsing, validation, option merging
│   │   └── NatsTransportConfigurationTest.php          # accessor coercion, defaults
│   └── Serializer/
│       ├── AbstractEnveloperSerializerTest.php
│       └── IgbinarySerializerTest.php
├── functional/                                 # Behat — requires a running NATS server (Docker)
│   ├── features/*.feature                      # setup, batching, consumer, nak, term, delayed, tls, mtls, stream_limits
│   ├── tests/Behat/NatsSetupContext.php        # all step definitions
│   └── src/, config/, bin/, public/            # a minimal Symfony app exercising the transport end-to-end
└── nats/                                       # Docker Compose + NATS configs + test TLS certs

docs/
├── CHANGELOG.md                                # Keep a Changelog format
├── TESTS.md                                    # Feature → test coverage map (kept in sync with the suite)
└── PRs/                                         # One file per merged/adapted PR (PR-NNN-*.md)
```

## Components

### NatsTransportFactory
- Recognizes two DSN schemes: `nats-jetstream://` and `nats-jetstream+tls://` (see `supports()`).
- Creates `NatsTransport`, forwarding Symfony's resolved serializer. When no serializer service is
  configured for the transport, the transport builds its own default (see Serialization below).
- Holds no state; one factory is shared for all NATS transports.

### NatsTransport
The heart of the bridge. Responsibilities map almost 1:1 to `TransportInterface`:

| Method | What it does |
|--------|--------------|
| `send()` | Stamps a UUIDv4 `TransportMessageIdStamp`, serializes the envelope, publishes to the subject. With headers it uses `requestWithHeaders()` and validates the JetStream publish ack. A `DelayStamp` (when `scheduled_messages` is on) is published to `{topic}.delayed.{uuid}` with `Nats-Schedule` headers. |
| `get()` | Pulls a batch via `fetchBatch(stream, consumer, batching, timeoutMs)`. JetStream 404 (consumer missing) and 408 (timeout / no messages) are treated as empty. Decodes each message and yields an `Envelope` carrying the JetStream reply token as its `TransportMessageIdStamp`. |
| `ack()` | Sends JetStream ACK for the message's reply token. |
| `reject()` | Delegates to `handleFailedDelivery()` → TERM (Symfony retry) or NAK (NATS retry). |
| `getMessageCount()` | Tries consumer info (`num_ack_pending` / `num_pending`), falls back to stream `state.messages`, then 0. |
| `setup()` | Creates the stream with configured retention/storage/replicas; if it already exists, reads the live config, merges subjects, and updates. Then creates a durable **pull** consumer with explicit ACK / deliver-all and asserts it matches expectations. |

Cross-cutting behavior:
- **Lazy connection.** No socket is opened in the constructor. `jetStream()` calls `connectIfNeeded()`,
  which connects on first use and caches the `JetStreamContext`.
- **Pull consumers, explicit ACK.** Messages are only considered processed once explicitly ACK'd;
  this is what makes `retry_handler` and shared-consumer load balancing work.

### Options/ — configuration model
Configuration is resolved once, up front, into an **immutable** object:

```
DSN string + options array
        │  NatsTransportConfigurationBuilder::build()
        ▼
parse_url() → host/port/path/query/credentials
        │  merge:  method options  >  DSN query  >  DEFAULT_OPTIONS
        │  validate (positive/non-negative numbers, enums, non-empty strings, NATS name charset)
        ▼
NatsClient (idct/php-nats-jetstream-client) built with NatsOptions (TLS + auth)
        ▼
NatsTransportConfiguration  ← immutable, typed accessors apply clamping/unit conversion
```

- **`TransportOption`** is the single source of truth for option keys; `DEFAULT_OPTIONS` must have an
  entry for every case (enforced by `testDefaultOptionsCoversAllTransportOptionCases`).
- **`NatsTransportConfiguration`** never returns raw option values — accessors normalize (e.g.
  `maxBatchTimeoutMs()` converts seconds→ms and clamps to ≥1, `streamMaxAgeSeconds()` clamps to ≥0).
- **`RetryHandler`** encodes the TERM-vs-NAK failure strategy.

### Serializer/ — payload encoding
- `AbstractEnveloperSerializer` implements Symfony's `SerializerInterface` `encode()`/`decode()`,
  wrapping the `body`/`headers` envelope shape and validating that decode produces a real `Envelope`.
- Concrete serializers only implement `serialize()`/`deserialize()` for the raw format.
- `IgbinarySerializer` is the default (compact binary). If `ext-igbinary` is missing, the transport
  emits a notice and falls back to Symfony's `PhpSerializer`.
- ⚠️ Both default serializers `unserialize()` raw payloads — only safe on trusted subjects. For
  untrusted publishers, supply a JSON/Protobuf serializer. See the README Security section.

### TypeCoercion
A `final` class of pure, `public static` `mixed → int|float|string` helpers. DSN query params arrive
as strings (`parse_str`) while YAML config is already typed, and JetStream JSON responses are loosely
typed; this class centralizes safe casting so every call site behaves the same. Used by the transport,
the configuration, and the builder, and covered by its own unit tests plus a functional scenario.

## Data flow examples

**Sending `new MyMessage()`**
1. `MessageBus::dispatch()` → Messenger routes to the NATS transport → `NatsTransport::send()`.
2. UUIDv4 stamp added → serializer encodes to `{body, headers}`.
3. No headers → `jetStream()->publish(topic, body)->await()`. With headers → `requestWithHeaders()` +
   `assertJetStreamPublishSucceeded()`.

**Consuming**
1. `messenger:consume nats_transport` loops `NatsTransport::get()`.
2. `fetchBatch(...)` pulls up to `batching` messages (bounded by `max_batch_timeout`).
3. Each non-empty message is decoded and yielded with its reply token.
4. Handler success → `ack()`; failure/exception → `reject()` → TERM or NAK per `retry_handler`.

**Setup**
1. `messenger:setup-transports` → `NatsTransport::setup()`.
2. `createStream()`; on conflict, `getStream()` → merge subjects → `updateStream()`.
3. `createConsumer()` (durable, pull, explicit ACK, deliver-all) → assert configuration.

## Dependency boundary

All NATS specifics live behind `idct/php-nats-jetstream-client` (namespace `IDCT\NATS\…`). This
library imports its `NatsClient`, `JetStreamContext`, `NatsMessage`, `NatsHeaders`, `Schedule`, the
`Models\*` value objects, the `Enum\StorageBackend`, and `JetStreamException`. The client is async
(amphp `Future`); this bridge is the synchronous adapter Symfony expects.
