# Symfony NATS Messenger Bridge

[![PHP Version](https://img.shields.io/badge/PHP-^8.2-787CB5?logo=php&logoColor=white)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/Symfony-^7.2%20%7C%20^8.0-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Unit Tests Coverage](https://img.shields.io/badge/Coverage-99.58%25-brightgreen)](https://github.com/ideaconnect/symfony-nats-messenger/actions)
[![Mutation MSI](https://img.shields.io/badge/Mutation%20MSI-100%25-brightgreen)](https://infection.github.io/)
[![Functional Tests](https://img.shields.io/badge/Functional%20Tests-Behat-blue)](tests/functional)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![CI](https://github.com/ideaconnect/symfony-nats-messenger/actions/workflows/ci.yml/badge.svg)](https://github.com/ideaconnect/symfony-nats-messenger/actions/workflows/ci.yml)
![Made in the EU](https://raw.githubusercontent.com/ideaconnect/made-in-the-eu/main/software-badge/made-in-the-eu.svg)

A Symfony Messenger transport integration for [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream), enabling reliable asynchronous messaging with persistent message streaming.

## Features

- 🚀 **High-Performance Messaging** - Leverage NATS JetStream for fast, reliable message delivery
- 📦 **Symfony Integration** - Implements Messenger's `TransportInterface`, `MessageCountAwareInterface`, `SetupableTransportInterface`, `KeepaliveReceiverInterface`, and `CloseableTransportInterface`
- ⚙️ **Configurable Consumers** - Support for multiple consumer strategies
- 🔄 **Flexible Batching** - Adjustable message batch sizes and timeouts
- 🔐 **Authentication Support** - Built-in support for NATS authentication
- 📊 **Stream Configuration** - Configurable retention policies and replication
- 🧪 **Thoroughly Tested** - 303 unit tests, ~99.6% coverage, mutation-tested (100% MSI)

## 🚀 This project looks for funding. Love my work? Support it! 💖

* ☕ **Buy me a coffee**: https://buymeacoffee.com/idct

* 💝 **Sponsor**: https://github.com/sponsors/ideaconnect

## Requirements

### System Requirements
- **PHP**: ^8.2
- **Symfony**: ^7.2 || ^8
- **NATS Server**: ^2.9 with JetStream enabled, ^2.12 for scheduled messages support.

## Installation

```bash
composer require idct/symfony-nats-messenger
```

### Development Setup

For contributors and development:

```bash
# Install dependencies
composer install

# Run static analysis and the default unit test suite after every modification
composer test

# Start NATS server for testing
composer nats:start

# Run unit tests with coverage
composer test:unit

# Set up functional tests
composer test:functional:setup

# Run functional tests
composer test:functional

# Stop NATS server
composer nats:stop
```

## Quick Start

### 1. Configure NATS Server

Ensure your NATS server has JetStream enabled:

```bash
nats-server -js
```

### 2. Set Up Transport in Symfony

Add the NATS transport to your Symfony Messenger configuration:

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic'
        options:
          consumer: 'my-consumer'
          batching: 5
          max_batch_timeout: 1.0

    routing:
      'App\Message\MyAsyncMessage': nats_transport
```

> **Tested by:** `testReadmeDsnExamplesParseSuccessfully[README: quick-start transport]`, `testReadmeConfigurationOptionsAreAccepted`, `testReadmeBatchingExamplesAreAccepted`, `testReadmeTimeoutExamplesAreAccepted`

### 3. Configure Custom Serializers (Optional)

This transport ships with a high-performance `IgbinarySerializer`, but under the Symfony framework it is **not** selected automatically. Symfony Messenger always resolves a serializer and passes it to the transport factory - its framework default is the native `PhpSerializer` - so to use igbinary you must set the transport's `serializer:` key explicitly (shown below). The transport's *own* igbinary auto-selection (and the `PhpSerializer` fallback, with an `E_USER_WARNING`, when `ext-igbinary` is unavailable) only applies when you construct `NatsTransport` directly without passing a serializer, e.g. outside the framework.

#### Using IgbinarySerializer (recommended)

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic'
        serializer: 'IDCT\NatsMessenger\Serializer\IgbinarySerializer'
        options:
          consumer: 'my-consumer'
```

Register the serializer service the `serializer:` key refers to. For example:
```yaml
    igbinary_serializer:
        class: IDCT\NatsMessenger\Serializer\IgbinarySerializer
```

or:
```yaml
    IDCT\NatsMessenger\Serializer\IgbinarySerializer: ~
```

Like Symfony's own serializers, it leaves out the stamps Symfony marks as not to be sent
(`NonSendableStampInterface`), such as the `AckStamp` a worker adds to the message it handles, so a message
Symfony re-sends for a retry or to the failure transport is serialized without them. Any serializer extending
`AbstractEnveloperSerializer` does the same.

> **Tested by:** `createTransport_UsesProvidedSerializer`, `serialize_WithValidEnvelope_ReturnsSerializedString`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `testConstructorWithoutIgbinaryDoesNotCrash`, `encode_EnvelopeAWorkerIsHandling_EncodesWithoutItsNonSendableStamps`, `encode_WithNonSendableStamps_LeavesThemOutAndKeepsTheRest`, Behat scenario `Failed message routed to Symfony failure transport via TERM with the igbinary serializer`

#### Creating Custom Serializers

You can create your own serializer by extending `AbstractEnveloperSerializer`:

```php
use IDCT\NatsMessenger\Serializer\AbstractEnveloperSerializer;
use Symfony\Component\Messenger\Envelope;

class MyCustomSerializer extends AbstractEnveloperSerializer
{
    protected function serialize(Envelope $envelope): string
    {
        // Your custom serialization logic
        return serialize($envelope);
    }

    protected function deserialize(string $data): mixed
    {
        // Your custom deserialization logic
        return unserialize($data);
    }
}
```

> **Tested by:** `readmeCustomSerializerExample_EncodeDecode_RoundTrips`, `readmeCustomSerializerExample_DecodeInvalidBody_ThrowsException` - the exact code above is compiled and exercised via `ReadmeExampleSerializer` in the unit tests.

For reference implementations, see:
- `src/Serializer/IgbinarySerializer.php` - Binary serialization
- `src/Serializer/AbstractEnveloperSerializer.php` - Base class

### 4. Send Messages

```php
use App\Message\MyAsyncMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class MyController
{
    public function __construct(private MessageBusInterface $bus) {}

    public function send(): void
    {
        $this->bus->dispatch(new MyAsyncMessage('Hello NATS!'));
    }
}
```

> **Tested by:** `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, Behat scenario `Complete message flow - send, check stats, consume, verify`

### 5. Handle Messages

```php
use App\Message\MyAsyncMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class MyAsyncMessageHandler
{
    public function __invoke(MyAsyncMessage $message): void
    {
        echo "Processing: " . $message->getText();
    }
}
```

> **Tested by:** Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, and `High-volume message processing with file output verification` - handlers are exercised through real `messenger:consume` runs.

### 6. Consume Messages

```bash
symfony console messenger:consume nats_transport
```

> **Tested by:** Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, and `Partial message consumption with multiple consumers` - the Behat context runs `messenger:consume` as a Symfony CLI process.

## Configuration Guide

### DSN Format

```
nats-jetstream://[user:password@]host:port/stream-name/topic-name
```

> **Tested by:** `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `createTransport_WithValidDsn_ReturnsNatsTransportInstance`

**Examples:**

```yaml
# Default port (4222)
nats-jetstream://localhost/my-stream/my-topic

# Custom port
nats-jetstream://localhost:5000/my-stream/my-topic

# With authentication
nats-jetstream://user:password@localhost:4222/my-stream/my-topic

# With query parameters
nats-jetstream://localhost/my-stream/my-topic?consumer=worker&batching=10

# TLS transport scheme
nats-jetstream+tls://localhost:4222/my-stream/my-topic
```

> **Tested by:** `testReadmeDsnExamplesParseSuccessfully` - each DSN above is parsed through the configuration builder via a dedicated data provider case.

### Configuration Options

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic'
        options:
          # Consumer Configuration
          consumer: 'my-consumer'           # Consumer group name (default: 'client')

          # Performance Tuning
          batching: 5                       # Messages per batch (default: 1)
          max_batch_timeout: 1.0            # Timeout in seconds for batch fetching (default: 1)
          connection_timeout: 1.0           # Connection (dial) timeout in seconds (default: 1)
          ping_after_idle: 30               # Seconds the connection may go unused before the next
                                            # operation checks it with a PING and dials again if the
                                            # server does not answer (default: 30; 0 turns it off).
                                            # See "Losing the Connection".
          request_timeout: 10               # Seconds to wait for the server's reply to a publish,
                                            # an ack_sync ACK, setup() or a message count (default: 10)

          # Consumer Flow Control & Lifecycle
          max_ack_pending: 1000             # Max delivered-but-unacked messages outstanding
                                            # (null = server default). Primary flow-control lever.
          inactive_threshold: 300           # Seconds of no pull activity before NATS removes the
                                            # durable consumer (null = server default).
          replay_policy: 'instant'          # instant|original (default: null = server default 'instant')
                                            # ⚠️ Immutable once the durable consumer exists: NATS rejects
                                            # the change, so the transport skips it and the option only
                                            # takes effect on a freshly created consumer.

          # Stream Retention Policies
          stream_max_age: 86400             # Max message age in seconds (0 = unlimited, default: 0)
          stream_max_bytes: 1073741824      # Max storage size in bytes (null = unlimited)
          stream_max_messages: 1000000      # Max number of messages in the stream (null = unlimited)
          stream_max_messages_per_subject: 1000 # Max number of messages retained per subject (null = unlimited)
          stream_max_message_size: 1048576  # Max size of a single message in bytes.
                                            # Must be a positive integer, at most 2147483647.
                                            # Unset means "leave it to the server": a new stream gets
                                            # JetStream's unlimited default, and an existing stream
                                            # keeps whatever limit it already has. To lift a limit on
                                            # an existing stream, change it in NATS directly.
          stream_max_consumers: 10          # Max consumers allowed on the stream (null = unlimited)
                                            # ⚠️ NATS up to 2.11 refuses to change this on an existing
                                            # stream. When left unset the transport keeps whatever the
                                            # stream already has, so it never breaks an existing setup.

          # Stream Retention & De-duplication Behavior
          stream_retention: 'limits'        # limits|interest|workqueue (default: null = server default 'limits').
                                            # ⚠️ Immutable once the stream exists: NATS rejects changing it,
                                            # so a changed value is ignored on an existing stream - recreate
                                            # the stream to change retention (see note below).
          stream_discard: 'old'             # old|new - what to drop when a limit is hit (default: null = 'old')
          stream_duplicate_window: 120      # De-duplication window in seconds (null = server default).
                                            # Must be a positive integer; 0 is rejected because NATS
                                            # would substitute its own 2-minute default.
                                            # ⚠️ NATS forbids a window larger than a finite max age, so
                                            # lowering stream_max_age below the stream's current window
                                            # rewrites the window down to match (the server does the
                                            # same at creation time). Raising stream_max_age again does
                                            # not restore it - set this option explicitly to do that.
                                            # Must not exceed stream_max_age when that is set.

          # Storage Backend
          stream_storage: 'file'            # Storage type: 'file' or 'memory' (default: 'file')
                                            # ⚠️ Immutable once the stream exists (like stream_retention).
          stream_compression: 'none'        # none|s2 (default: null = server default 'none')
          stream_description: 'my stream'   # Human-readable stream description (null = unset).
                                            # At most 4096 characters.

          # Stream Access Policy (null = leave the server default untouched)
          stream_deny_delete: false         # Deny message deletion from the stream.
                                            # ⚠️ One-way: NATS can turn this on but never off, so once
                                            # a stream denies deletes, setting false here is ignored.
          stream_deny_purge: false          # Deny stream purge (one-way, exactly like deny_delete)
          stream_allow_direct: false        # Allow direct get access
          stream_allow_rollup_headers: false # Allow Nats-Rollup headers

          # High Availability
          stream_replicas: 1                # Number of replicas (default: 1)

          # Failure Handling Strategy
          retry_handler: 'symfony'          # symfony|nats (default: symfony)
                                            # symfony => TERM on failed/rejected message
                                            # nats    => NAK on failed/rejected message

          # NATS-native Redelivery Tuning (mainly relevant with retry_handler: nats)
          nak_delay: 0                      # Seconds to wait before NATS redelivers a NAK'd
                                            # message (default: 0 = immediate). Use to back off
                                            # instead of hot-looping a failing message.
          ack_wait: null                    # Seconds JetStream waits for an ACK before redelivering
                                            # (default: null = server default, ~30s). Raise it for
                                            # slow handlers to avoid premature redelivery.
          max_deliver: null                 # Max redelivery attempts before NATS gives up
                                            # (default: null = unlimited). Set it to stop a poison
                                            # message redelivering forever under retry_handler: nats.
          backoff: null                     # List of per-attempt delays in seconds, e.g. [1, 5, 30].
                                            # Pairs with max_deliver. (default: null)

          # Acknowledgement Mode
          ack_sync: false                   # Wait for server confirmation of each ACK (default: false)
                                            # false => fire-and-forget ACK (lower latency)
                                            # true  => JetStream double-ack; a dropped ACK cannot
                                            #          silently cause redelivery, at a latency cost

          # Scheduled / Delayed Messages (requires NATS >= 2.12)
          scheduled_messages: false         # Enable scheduled message support (default: false)
                                            # When enabled, Symfony DelayStamp triggers NATS
                                            # scheduled message delivery via Nats-Schedule headers

          # Provisioning
          auto_setup: false                 # Provision the stream/consumer on first send/get (default: false).
                                            # When false (the default), run `messenger:setup-transports`
                                            # explicitly. When true, setup() runs once, lazily, on first use.

          # TLS Configuration
          tls_required: false               # Force TLS for NATS connection (default: false)
          tls_handshake_first: false        # Use TLS-first handshake mode (default: false)
          tls_ca_file: null                 # Path to CA certificate file
          tls_cert_file: null               # Path to client certificate file
          tls_key_file: null                # Path to client private key
          tls_key_passphrase: null          # Passphrase for encrypted private key
          tls_peer_name: null               # Override TLS peer name for certificate validation
          tls_verify_peer: true             # Verify TLS peer certificate (default: true). Only
                                            # false, 0, no or off turns it off; any other value,
                                            # an empty one included, keeps it on

          # Additional Authentication
          token: null                       # NATS token authentication
          username: null                    # Overrides DSN username if provided
          password: null                    # Overrides DSN password if provided
          jwt: null                         # JWT authentication value
          nkey: null                        # NKey public value
```

> **Tested by:** `testReadmeConfigurationOptionsAreAccepted` (all options above), `testReadmeBatchingExamplesAreAccepted`, `testReadmeTimeoutExamplesAreAccepted`, `testReadmeStreamRetentionExamplesAreAccepted`, `testBuildAcceptsAndNormalizesNewStreamAndConsumerOptions`, `testSetupPassesNewStreamPolicyOptions`, `testSetupPassesNewConsumerOptions`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, `testTlsVerifyPeerStaysOnUnlessExplicitlyDisabled`, `testTlsVerifyPeerInTheDsnStaysOnUnlessExplicitlyDisabled`

### Retry Handler Behavior

- `retry_handler: symfony` (default) sends `TERM` when a message fails during transport decoding or is rejected. Symfony's retry/failure transport then handles redelivery.
- `retry_handler: nats` sends `NAK` when a message fails during transport decoding or is rejected, so NATS redelivers the message itself.

**Symfony's retry delays need `scheduled_messages: true`.** Symfony's retry strategy waits between attempts
(1, 2 and 4 seconds with the framework's default strategy) by re-sending the failed message with a
`DelayStamp`. The transport applies a `DelayStamp` only when `scheduled_messages` is enabled, which needs
NATS 2.12 or later, and otherwise publishes the message at once. So with the defaults, each retry runs right
after the failure, all of them are used up within milliseconds, and nothing reports that the delays were
dropped. To keep the delays:

- on NATS 2.12 or later, enable `scheduled_messages` (see [Delayed / Scheduled Messages](#delayed--scheduled-messages));
- on an older server, let NATS space out the attempts instead: `retry_handler: nats` with `nak_delay` or
  `backoff`, bounded by `max_deliver`, and with `max_retries: 0` (see below).

> **Tested by:** `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally`, `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`

When NATS manages redelivery (`retry_handler: nats`), tune it with `nak_delay`, `ack_wait`, `max_deliver`, and `backoff`:

- **`nak_delay`** delays each NAK so a failing message backs off instead of redelivering immediately (a hot loop).
- **`max_deliver`** caps redeliveries. ⚠️ Without it, `retry_handler: nats` redelivers a permanently-failing ("poison") message **forever** - set `max_deliver` in production.
- **`backoff`** sets an escalating per-attempt delay schedule (e.g. `[1, 5, 30]` seconds); pair it with `max_deliver` greater than the number of backoff steps.
- **`ack_wait`** is how long JetStream waits for an ACK before considering a delivery failed and redelivering - raise it for handlers that legitimately take a while.

**Symfony's retry strategy is ignored with `retry_handler: nats`.** Symfony's retry listener runs for every
transport, and FrameworkBundle gives each one a retry strategy (`max_retries: 3` by default). In nats mode the
transport does not publish the copy that retry sends, because NATS redelivers the original after the NAK, so
`max_retries`, `delay` and `multiplier` have no effect and `max_deliver` bounds the attempts. Set
`max_retries: 0` on the transport anyway: with retries left on, Symfony still logs each failure as a retry it
never makes ("Sending for retry #1") and never treats the message as failed for good.

A failure transport does not combine well with nats mode. Symfony sends a message there each time it treats a
failure as final: with `max_retries: 0` that is every failed delivery, so a message that keeps failing lands
there up to `max_deliver` times, and with retries left on it never lands there. For a transport in nats mode,
rely on NATS's own handling of a message that keeps failing instead (`max_deliver`, and the JetStream
`MAX_DELIVERIES` advisory).

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic?retry_handler=nats&max_deliver=5&nak_delay=10'
        retry_strategy:
          max_retries: 0
```

> **Tested by:** `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesNakWithDelayWhenConfigured`, `testSetupAppliesConsumerRetryTuning`, `testBuildAcceptsNatsRetryTuningOptions`, `testBuildUsesRetryHandlerFromQuery`, `testNatsModeDoesNotPublishTheCopySymfonysRetrySends`, `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy`, Behat scenarios `nats_nak.feature`, `nats_term.feature` and `Symfony's retry strategy is ignored with the NATS retry handler`

### Long-Running Handlers (`--keepalive`)

NATS redelivers a message that is not acknowledged within the consumer's `ack_wait` (30 seconds unless set),
possibly to another worker, even while a handler is still working on it. `messenger:consume --keepalive`
prevents that: every few seconds (5 by default, `--keepalive=N` to change it) Symfony has the transport tell
NATS that the message is still in progress, which restarts its `ack_wait`.

```bash
symfony console messenger:consume nats_transport --keepalive=10
```

Symfony sends the keepalive from a `SIGALRM` signal handler, which needs the `pcntl` extension and can
interrupt the handler at any point. The NATS client is asynchronous, and PHP does not let it wait inside a
signal handler, so the transport queues the in-progress acknowledgement there, and it goes out the next time
the event loop runs:

- **A handler that waits on asynchronous work** - any call to NATS through this library, such as dispatching
  a message to a NATS transport, or amphp-based I/O - lets it out at once, so the keepalive works as intended.
- **A handler that only blocks** - plain PHP work, or blocking calls such as PDO queries or curl requests -
  lets it out only once it returns, too late to restart the timer. For such a handler, set `ack_wait` longer
  than its longest run instead.

Keep the keepalive interval well below `ack_wait`, so a keepalive that goes out a little late still arrives in
time.

> **Tested by:** `testKeepaliveSendsInProgressForReplyToken`, `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting`, `testKeepaliveDoesNotWaitForTheAcknowledgement`, `testKeepaliveWithoutAConnectionSendsNothingAndDoesNotDial`, `testKeepaliveWhoseAcknowledgementFailsRaisesNoUnhandledError`, Behat scenario `A message handled for longer than ack_wait is not redelivered while keepalive runs`

## Important: Consumer Strategies

This is critical to understand before setting up multiple transport instances:

### ⚠️ Strategy A: Same Consumer, Batching = 1

**Use when:** Multiple instances should cooperate on the same consumer

```yaml
# All instances use the same consumer with batching=1
transports:
  nats_worker_1:
    dsn: 'nats-jetstream://localhost/my-stream/my-topic'
    options:
      consumer: 'shared-consumer'  # Same consumer name
      batching: 1                  # MUST be 1 for shared consumers

  nats_worker_2:
    dsn: 'nats-jetstream://localhost/my-stream/my-topic'
    options:
      consumer: 'shared-consumer'  # Same consumer name
      batching: 1                  # MUST be 1 for shared consumers
```

**Why batching must be 1:**
- With explicit acknowledge (ACK) mode, only messages that are explicitly acknowledged are considered processed
- Multiple instances sharing the same consumer need to ACK individually
- Batching > 1 with multiple instances causes delivery conflicts
- Each instance should fetch and ACK one message at a time

**Benefits:**
- Automatic load balancing across instances
- NATS handles message distribution
- Guaranteed single processing per message

> **Tested by:** `testReadmeBatchingExamplesAreAccepted` (batching=1), Behat scenario `Partial message consumption with multiple consumers`

### ✅ Strategy B: Different Consumers, Any Batching

**Use when:** Each instance needs independent message processing (duplicates allowed)

```yaml
# Each instance uses a different consumer
transports:
  nats_worker_1:
    dsn: 'nats-jetstream://localhost/my-stream/my-topic'
    options:
      consumer: 'worker-1-consumer'   # Unique consumer per instance
      batching: 10                    # Can use any batching

  nats_worker_2:
    dsn: 'nats-jetstream://localhost/my-stream/my-topic'
    options:
      consumer: 'worker-2-consumer'   # Unique consumer per instance
      batching: 10                    # Can use any batching
```

**Why this works:**
- Each consumer maintains its own state
- All messages are delivered to all consumers independently
- Each instance can use higher batching for better throughput
- Duplicate processing is expected (fan-out pattern)

**Use cases:**
- Event broadcasting to multiple systems
- Multiple independent processors
- Audit logging / event replay

> **Tested by:** `testReadmeBatchingExamplesAreAccepted` (batching=10), Behat scenario `Partial message consumption with multiple consumers`

## Batching & Timeouts

### Batching Explained

- **Higher batching**: Better throughput, slightly higher latency
- **Lower batching**: Lower latency, slightly reduced throughput
- **Optimal batching**: Depends on message size and processing time

```yaml
options:
  batching: 1        # Fetch 1 message at a time (low latency)
  batching: 5        # Fetch 5 messages (balanced)
  batching: 20       # Fetch 20 messages (high throughput)
```

> **Tested by:** `testReadmeBatchingExamplesAreAccepted` - values 1, 5, 10, 20, 50 are all verified.

### Batch Timeout

Controls how long to wait for a batch to fill:

```yaml
options:
  batching: 10
  max_batch_timeout: 0.5  # Wait max 0.5s for batch to fill
                          # Returns early if timeout reached
```

> **Tested by:** `testReadmeTimeoutExamplesAreAccepted` - values 0.5, 1.0, 2.0 are verified. Behat scenarios `nats_batching.feature`.

**Example scenarios:**
- If you set `batching: 10` and `max_batch_timeout: 0.5`
- If 10 messages arrive quickly, all are fetched immediately
- If only 3 messages arrive in 0.5s, return those 3

### Connection Timeout

Controls the timeout for establishing the NATS connection: the first dial, and each new dial after the connection was lost (see [Losing the Connection](#losing-the-connection)):

```yaml
options:
  connection_timeout: 2.0  # Connection (dial) timeout in seconds
```

> **Tested by:** `testReadmeTimeoutExamplesAreAccepted` (1.0, 2.0, 3.0), `testBuildWithConnectionTimeoutPropagatesMs`

**Purpose:**
- Sets the timeout for the initial TCP/TLS dial and handshake when connecting to NATS
- Also bounds the PING that checks a connection which sat idle (`ping_after_idle`): a server that does not answer within it is treated as gone
- Does **not** bound how long an operation waits for the server's reply, which is `request_timeout`; the batch fetch is bounded separately by `max_batch_timeout`
- Bounds each dial attempt: a dial that fails is tried three times, with pauses of 2 and 4 seconds in between
- Lower values fail faster only when an attempt hangs, as with an unreachable host (about 6 seconds plus three times the value in all); a refused connection fails after about 6 seconds whatever the value
- Higher values tolerate slower connection establishment

**When to adjust:**
- Increase for high-latency networks or geographically distant NATS servers
- Decrease for faster failure detection in local environments
- Default of 1 second works well for most local/regional deployments

### Request Timeout

Controls how long an operation waits for the server's reply: the publish acknowledgement of `send()`, the
confirmation of an ACK with `ack_sync`, and the JetStream API calls of `setup()` and `getMessageCount()`:

```yaml
options:
  request_timeout: 30  # seconds (default: 10)
```

> **Tested by:** `testRequestTimeoutDefaultsToTenSeconds`, `testBuildWithRequestTimeoutPropagatesMs`, `testBuildWithInvalidRequestTimeoutThrowsException`, `testReadmeTimeoutExamplesAreAccepted` (10, 30)

A reply that does not arrive in time fails the operation with the client's `TimeoutException`. For `send()`
that does not prove the message was not stored: the server may have stored it and only its acknowledgement
was late, so dispatching the message again can store it twice. Raise the value for a slow or distant server,
or for a stream whose writes can take long, such as a replicated one under load; lower it to fail faster.
The wait of a pull in `get()` is bounded by `max_batch_timeout` instead, and the dial by
`connection_timeout`.

### Losing the Connection

The transport does not reconnect in the background, and nothing in Symfony calls `close()`. When the
connection has been lost, the next operation dials again instead:

- A connection is lost when the NATS server restarts, when the network drops it, or when the server closes
  it because the client stopped answering its pings. A PHP process answers them only while it is inside a
  transport call, so a server with default settings drops a connection that has been idle for about 6 to 8
  minutes; a load balancer or NAT gateway may drop an idle connection sooner.
- The operation that runs into the lost connection fails. For `send()` that means the outcome is unknown:
  the server may or may not have stored the message. Once the client reports the connection closed, the
  next operation opens a new one. A consumer worker exits on the failed `get()` and its supervisor starts a
  new one; a process that sends for longer than one request (a web app in worker mode such as FrankenPHP or
  RoadRunner, a daemon, a handler that dispatches) keeps working from the next operation on.
- With `auto_setup`, the new connection is verified in the same call that dials it, and a dial that fails
  surfaces as the connection error itself.

A connection that was dropped while it sat idle would make the next operation the one that fails. To avoid
that, an operation that finds the connection unused for longer than `ping_after_idle` (30 seconds by
default) first checks it with one PING. If the server does not answer within `connection_timeout`, the
transport closes that connection and runs the operation on a new one, so the first message after a quiet
period goes out instead of failing. The PING costs one round trip, and only after a quiet period. A lower
value also catches a server restart between two operations further apart than it, at the cost of that round
trip more often. The same check follows an operation that failed on the connection - a JetStream error
reply does not count, since the server answered it - because the client can keep reporting a dead connection
open, and a connection that silently stopped delivering only times out. `ping_after_idle: 0` turns both
checks off. `keepalive()` never checks: Symfony calls it from a signal handler.

```yaml
options:
  ping_after_idle: 30   # seconds; 0 turns the check off
```

> **Tested by:** `testOperationAfterTheClientClosedDialsAgain`, `testAutoSetupVerifiesTheNewConnectionBeforeTheSameCallPublishes`, `testFailedDialAfterTheClientClosedSurfacesAsTheConnectionErrorAndIsRetried`, `testIdleConnectionIsCheckedWithAPingAndKeptWhenTheServerAnswers`, `testIdleConnectionThatDoesNotAnswerThePingIsReplacedBeforeTheOperation`, `testPingUnansweredWithinTheConnectionTimeoutReplacesTheConnection`, `testRecentlyUsedConnectionIsNotPinged`, `testPingAfterIdleZeroTurnsTheCheckOff`, `testKeepaliveNeitherPingsNorDials`, `testOperationThatFailedOnTheConnectionMakesTheNextOneCheckItFirst`, `testJetStreamReplyDoesNotMakeTheNextOperationCheckTheConnection`, `testBuildWithInvalidPingAfterIdleThrowsException`

## Stream Configuration

### Retention Policies

Control how long messages are kept in the stream:

```yaml
options:
  # By age (24 hours)
  stream_max_age: 86400

  # By total size (1GB)
  stream_max_bytes: 1073741824

  # By total message count across the entire stream (NATS: max_msgs)
  stream_max_messages: 1000000

  # By message count per individual subject (NATS: max_msgs_per_subject)
  stream_max_messages_per_subject: 1000

  # Unlimited (default)
  stream_max_age: 0
  stream_max_bytes: null
  stream_max_messages: null
  stream_max_messages_per_subject: null
```

> **Tested by:** `testReadmeStreamRetentionExamplesAreAccepted` - all retention options above are verified. Behat scenarios `nats_stream_limits.feature`.

> **Note:** `stream_max_messages` limits the total number of messages stored in the stream (maps to NATS `max_msgs`), while `stream_max_messages_per_subject` limits messages retained per individual subject (maps to NATS `max_msgs_per_subject`). The per-subject limit is especially useful with [multi-subject streams](#multi-subject-streams) to prevent one high-volume subject from dominating retention.

### High Availability

```yaml
options:
  # Single replica (no redundancy)
  stream_replicas: 1

  # 3 replicas (recommended for production)
  stream_replicas: 3
```

> **Tested by:** `testReadmeStreamRetentionExamplesAreAccepted` (replicas 1 and 3), `testSetupPassesConfiguredStreamOptions`

## Testing

### Unit Tests

```bash
# Install dependencies
composer install

# Run static analysis and the fast unit suite after every modification
composer test

# Run NATS
composer nats:start

# Run all unit tests with coverage (recommended)
composer test:unit

# Or run tests manually
./vendor/bin/phpunit
```

The target is to have at least 90% of code coverage.

**What's tested:**
- DSN parsing and validation
- Configuration option handling
- Authentication support
- Port configuration
- Error handling
- Interface compliance

### Mutation Tests

The unit suite is mutation-tested with [Infection](https://infection.github.io/) to ensure the tests
actually detect behavioral changes (not just execute lines):

```bash
# Requires a coverage driver (xdebug or pcov)
composer test:mutation
```

Configuration lives in `infection.json5`. It enforces a minimum MSI of 90% and a minimum covered MSI of
95%; the suite currently scores 100% covered MSI with 100% mutation code coverage. CI runs it on the
PHP 8.5 job.

### Functional Tests

Functional tests require a running NATS server with JetStream enabled:

```bash
# Set up functional test dependencies
composer test:functional:setup

# Start NATS server in Docker
composer nats:start

# Run functional tests
composer test:functional

# Stop NATS server
composer nats:stop
```

**Manual approach:**
```bash
# Set up NATS in Docker (optional)
cd tests/nats
docker-compose up -d

# Run functional tests
cd ../functional
./vendor/bin/behat features/

# Stop NATS
cd ../nats
docker-compose down
```

**What's tested:**
- Message publishing
- Message consumption
- Message acknowledgment
- Consumer setup
- Stream persistence

**See also:** `tests/functional/README.md`

## Advanced Usage

### Multiple Transports

Set up multiple independent transports for different use cases:

```yaml
framework:
  messenger:
    transports:
      # High-priority, low-latency messages
      nats_fast:
        dsn: 'nats-jetstream://localhost/fast-stream/fast-topic'
        options:
          consumer: 'fast-consumer'
          batching: 1

      # Bulk processing, high throughput
      nats_bulk:
        dsn: 'nats-jetstream://localhost/bulk-stream/bulk-topic'
        options:
          consumer: 'bulk-consumer'
          batching: 50

      # Audit logging
      nats_audit:
        dsn: 'nats-jetstream://localhost/audit-stream/audit-topic'
        options:
          consumer: 'audit-consumer'
          stream_max_age: 2592000  # 30 days
          stream_replicas: 3
```

> **Tested by:** `testReadmeDsnExamplesParseSuccessfully[README: fast transport]`, `testReadmeDsnExamplesParseSuccessfully[README: bulk transport]`, `testReadmeDsnExamplesParseSuccessfully[README: audit transport]`, `testReadmeAuditTransportOptionsAreAccepted`, `testReadmeBatchingExamplesAreAccepted`

### Multi-Subject Streams

Multiple transports can share the same NATS stream with different subjects. When `messenger:setup-transports` runs, each transport adds its subject to the existing stream rather than overwriting it:

```yaml
framework:
  messenger:
    transports:
      # Both transports share the "events" stream
      nats_orders:
        dsn: 'nats-jetstream://localhost/events/orders'
        options:
          consumer: 'order-consumer'
          batching: 1
          stream_max_age: 300

      nats_payments:
        dsn: 'nats-jetstream://localhost/events/payments'
        options:
          consumer: 'payment-consumer'
          batching: 2
```

The `events` stream will have both `orders` and `payments` as subjects.

> **Tested by:** `testReadmeDsnExamplesParseSuccessfully[README: multi-subject orders]`, `testReadmeDsnExamplesParseSuccessfully[README: multi-subject payments]`, `testReadmeMultiSubjectOptionsAreAccepted`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenario `Setup command merges subjects for transports sharing one stream`

> **Note:** When a stream already exists, setup reads the current JetStream configuration, merges in any new subjects, and then overlays the stream settings managed by this transport. Existing subjects are preserved, duplicate subjects are not added, and the existing storage backend is kept for already-created streams.

### Provisioning the Stream & Consumer

The stream and its durable pull consumer must exist before messages flow. There are two ways to
provision them.

**Explicit (default, recommended for production).** Provision once via the Symfony command:

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost/my-stream/my-topic'
        options:
          consumer: 'my-consumer'
```

```bash
symfony console messenger:setup-transports nats_transport
```

This will:
1. Create the stream with the configured settings, or update an existing one (merging subjects and keeping
   the server's own fields)
2. Create the consumer with an explicit ACK policy
3. Verify that the consumer matches the configuration

**Automatic (`auto_setup`).** Set `auto_setup=true` to have the transport provision the stream and
consumer itself, lazily, on the first `send()`/`get()`. Setup then runs once per transport instance, and
again only if JetStream reports the stream or consumer as missing during a pull (for example after NATS
removed an idle consumer that hit `inactive_threshold`), after `close()`, or after the transport dialled
again because the connection was lost (see [Losing the Connection](#losing-the-connection)). It defaults
to **`false`**, so unless you opt in, provisioning stays explicit (no hidden stream/consumer creation on
the hot path).

> ⚠️ **Re-provisioning replays the stream.** A durable consumer holds the acknowledgement state, so when
> one is lost that state is gone with it. The replacement is created with `deliver_policy=all` and will
> therefore redeliver every message the stream still retains, including messages that were already
> acknowledged. Under the default `limits` retention that means the whole stream, so make your handlers
> idempotent before combining `auto_setup` with a short `inactive_threshold`. `workqueue` retention does
> not have this problem, because acknowledged messages are removed from the stream. This is inherent to
> losing a durable consumer, not to `auto_setup`: a restarted worker rebuilds the consumer the same way.

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost/my-stream/my-topic?auto_setup=true'
```

> **Note on retention & storage:** `stream_retention` and `stream_storage` are fixed when the stream is
> created - NATS rejects changing either on an existing stream. On the update path the transport
> preserves the live server values, so changing these options on an already-created stream is a no-op.
> To change retention or storage, recreate the stream: delete it, then re-run
> `messenger:setup-transports`. With `auto_setup=true` a running worker recreates it on its next pull:
> deleting the stream deletes its consumers with it, so the pull fails with status 503 (nothing is left
> to answer it), which is one of the statuses that trigger re-provisioning.

> **Note on `replay_policy`:** the replay policy of a durable consumer is fixed when the consumer is
> created. NATS rejects an update that changes it, in both directions: once a consumer exists as
> `original`, removing the option again does not restore `instant` either, because the server reads the
> omitted field as a change back to its default. The transport therefore always writes the existing
> consumer's own replay policy and applies the configured one only to a consumer that does not exist
> yet. Changing it on a running deployment is a no-op until you delete the consumer and let setup
> recreate it.

> **Note on the deny flags:** `stream_deny_delete` and `stream_deny_purge` can be switched on but never
> off. NATS rejects an update that cancels either one, so on a stream that already denies deletes or
> purges the transport keeps the server's value and a `false` in your configuration is ignored. Recreate
> the stream to lift a deny. `stream_allow_direct` and `stream_allow_rollup_headers` are freely mutable
> in both directions.

> **Note on `stream_max_consumers`:** NATS servers up to and including 2.11 also refuse to change
> `max_consumers` on an existing stream. The transport therefore writes it only when you set the option
> explicitly; when you leave it unset, the stream keeps whatever value it already has. Setting it on an
> already-created stream works on NATS 2.12 and newer, and is rejected by the server on older versions -
> recreate the stream to change it there.

> **Tested by:** `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupPassesNewStreamPolicyOptions`, `testSetupPassesNewConsumerOptions`, `testAutoSetupProvisionsOnFirstSendOnce`, `testAutoSetupProvisionsOnFirstGet`, `testAutoSetupDisabledByDefaultDoesNotProvisionOnSend`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenarios `Setup NATS stream with max age configuration`, `Setup command handles existing streams gracefully`, and `Custom consumer name is registered in JetStream`

### Delayed / Scheduled Messages

**Requires NATS Server >= 2.12** with JetStream enabled. If `scheduled_messages` is enabled against an
older server, `messenger:setup-transports` fails with a clear, actionable error telling you to upgrade
NATS or disable the option.

Enable `scheduled_messages` in the DSN to use Symfony's `DelayStamp` for deferred delivery:

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost/my-stream/my-topic?scheduled_messages=true'
```

Then dispatch messages with a delay:

```php
use Symfony\Component\Messenger\Stamp\DelayStamp;

// Deliver after 30 seconds
$bus->dispatch(new MyMessage(), [new DelayStamp(30000)]);
```

> **Tested by:** `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`, `testSendDelayedMessageSchedulesAtRequestedDelay`, `testSendDelayedMessageNeverSchedulesBeforeRequestedDelay`, `testReadmeScheduledMessagesDsnEnablesFeature`, Behat scenarios `Delayed messages are delivered after the scheduled time` and `Delayed messages are not available to the consumer before the scheduled time`

When `scheduled_messages` is enabled and a `DelayStamp` is present:
- The message is published to a `{topic}.delayed.{uuid}` subject with `Nats-Schedule` and `Nats-Schedule-Target` headers
- The stream is created with an additional `{topic}.delayed.>` subject and `allow_msg_schedules` enabled
- NATS holds the message and delivers it to the original topic at the scheduled time
- The consumer processes it like any other message

The `DelayStamp` delay (milliseconds) maps onto a NATS `@at` schedule, which has **whole-second
resolution**. The delay is rounded **up** to the next whole second, so a message is never delivered
*before* the requested delay elapses (it may arrive up to ~1 second later); a sub-second delay therefore
schedules at the next whole second rather than firing immediately.

When `scheduled_messages` is disabled (the default), any `DelayStamp` on the envelope is silently ignored and messages are published immediately. That includes the `DelayStamp` Symfony's retry adds, so retries run back to back (see [Retry Handler Behavior](#retry-handler-behavior)).

### Stream Monitoring

View stream and consumer information:

```bash
# List streams
nats stream list

# View stream info
nats stream info my-stream

# List consumers
nats consumer list my-stream

# View consumer info
nats consumer info my-stream my-consumer

# View message count
nats consumer info my-stream my-consumer --json | jq '.state.num_pending'
```

### Manual Message Operations

```php
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

// Get message count
$count = $transport->getMessageCount();

// Check if messages are pending
if ($count > 0) {
    echo "Pending messages: $count";
}
```

`getMessageCount()`, which `messenger:stats` shows, returns 0 when NATS cannot be reached, the same as for an
empty queue, so during an outage the queue reads as empty there. It gives up after a single connection attempt
(about 6 seconds for a refused connection, see [Connection Timeout](#connection-timeout)). Monitor the
connection itself, or the stream with the `nats` CLI, rather than reading a 0 as "nothing to do".

> **Tested by:** `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending`, `testMessageCountThatCannotConnectDialsOnceAndReturnsZero`

## Troubleshooting

### Connection Issues

**Error: "Connection refused"**
```bash
# Check NATS is running
nats-server --js

# Verify host and port
nats-jetstream://localhost:4222/stream/topic
```

> **Tested by:** Behat scenario `Setup command fails gracefully when NATS is unavailable`

**Error: "Stream not found"**
```bash
# Run setup command to create stream
symfony console messenger:setup-transports nats_transport
```

> **Tested by:** `testSetupCreatesStreamAndConsumer`, `testSetupUpdatesStreamWhenItAlreadyExists`, Behat scenarios `Setup NATS stream with max age configuration` and `Setup command handles existing streams gracefully`

### Message Processing Issues

**Messages not being consumed**
```bash
# Check consumer exists
nats consumer list my-stream

# View consumer status
nats consumer info my-stream my-consumer

# Check for errors in consumer
nats consumer info my-stream my-consumer --json | jq '.state'
```

**Messages stuck in pending**
```bash
# Check handler is not throwing exceptions
# Verify handler implementation
# Check application logs for errors
```

## Architecture

The bridge consists of two main components:

### NatsTransportFactory
- Handles DSN scheme detection (`nats-jetstream://`)
- Creates `NatsTransport` instances
- Validates configuration

### NatsTransport
- Implements Symfony's `TransportInterface`, `MessageCountAwareInterface`, `SetupableTransportInterface`, `KeepaliveReceiverInterface`, and `CloseableTransportInterface`
- Manages stream and consumer connections
- Handles message serialization via a pluggable `SerializerInterface` (igbinary when constructed directly without one)
- Supports batching and explicit acknowledgment

## Performance Tips

1. **Choose appropriate batching**
   - Start with `batching: 5` for balanced performance
   - Increase to 20+ for high throughput workloads
   - Use 1 for strict low-latency requirements

2. **Set reasonable timeouts**
   - `max_batch_timeout: 0.5` for responsive systems
   - `max_batch_timeout: 2.0` for background jobs
   - `connection_timeout: 1.0` for local/regional deployments
   - `connection_timeout: 3.0+` for cross-region or high-latency networks

3. **Use appropriate replicas**
   - `stream_replicas: 1` for development
   - `stream_replicas: 3` for production

4. **Monitor performance**
   - Use `getMessageCount()` to track queue depth
   - Monitor handler execution time
   - Watch for stuck messages

## Security Considerations

### ⚠️ Deserialization of Untrusted Data

The default `IgbinarySerializer` (and any serializer extending `AbstractEnveloperSerializer`) deserializes raw message payloads from NATS into PHP objects. PHP object unserialization is a [well-known attack vector](https://owasp.org/Top10/A08_2021-Software_and_Data_Integrity_Failures/) - a crafted payload can trigger arbitrary code execution via magic methods (`__wakeup`, `__destruct`, etc.).

> **⚠️ PhpSerializer fallback:** when no serializer is configured **and `ext-igbinary` is not installed**, the transport automatically falls back to Symfony's `PhpSerializer`, which uses native `unserialize()` - the **same** untrusted-deserialization (object injection) risk as igbinary, not a safer alternative. The transport emits an `E_USER_WARNING` when this happens. Do not rely on the fallback in production: either install `ext-igbinary` or explicitly configure a serializer (ideally a safe-format one, per below).

**If your NATS topics are not fully trusted** (e.g. shared infrastructure, external publishers), you should:
- Implement a custom serializer that uses a safe format (JSON, Protobuf) instead of PHP object serialization
- Add message-level authentication (e.g. HMAC signatures) to verify publisher identity before deserializing
- Restrict NATS topic publish permissions via ACLs so only trusted services can publish

The type check (`instanceof Envelope`) happens *after* deserialization, which is too late to prevent exploitation.

### Stream-Exists Detection During Setup

During `setup()`, if `createStream` fails the transport detects a pre-existing stream **deterministically** by querying JetStream stream info: a `404` means the stream is absent (so the creation error was genuine and is rethrown), while a successful lookup means the stream exists (so it is updated, reusing the fetched configuration). This relies on the JetStream stream-info API rather than matching server-specific conflict strings (`"already in use"` / `"already exists"`), whose wording varies across NATS versions.

If you experience unexpected behavior during stream setup, confirm the stream can be queried via JetStream stream-info APIs and review the exact error returned by your NATS server version.

### Publish Response Validation

On `send()`, the transport awaits the JetStream publish acknowledgement returned by the client's `publish()` call. The client validates that acknowledgement and raises a `JetStreamException` if JetStream reports an error or returns an empty/malformed response, so a proxy or protocol misconfiguration fails closed instead of silently accepting an invalid publish acknowledgement.

### General Recommendations

1. **Authentication**
   - Prefer environment variables or explicit options for credentials over hard-coded DSNs
   - If you use credentials in a DSN, avoid logging the full DSN because it may expose secrets
   - Store credentials in environment variables
   - Never commit credentials to version control

2. **Message Encryption**
   - Encrypt sensitive data before dispatching
   - NATS can be configured with TLS for transit encryption
   - Implement application-level encryption for sensitive payloads

3. **Access Control**
   - Restrict stream/consumer creation to authorized users
   - Use NATS access control lists (ACLs) for fine-grained permissions
   - Audit stream operations

## Contributing

Contributions are welcome! Please ensure:
- Every modification runs the relevant verification commands before it is considered done
- Minimum verification for PHP changes: `composer test`
- All tests pass: `composer test:unit`
- Code coverage remains above 90%
- New features include corresponding tests
- Documentation is updated
- Functional tests pass: `composer test:functional` (if applicable)
- `docs/TESTS.md` is kept up to date when tests are added, removed, or renamed
- Each release has an entry in `docs/CHANGELOG.md` following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format
- When a PR is merged or its features are adapted, a description is added to `docs/PRs/`

### Quick Development Workflow

```bash
# 1. Run static analysis and the default unit suite after each modification
composer test

# 2. Set up functional tests (first time only)
composer test:functional:setup

# 3. Start NATS for functional tests
composer nats:start

# 4. Run functional tests
composer test:functional

# 5. Clean up
composer nats:stop
```

## License

MIT License - see LICENSE file for details

## Support

For issues, questions, or suggestions:
1. Check the [troubleshooting](#troubleshooting) section
2. Check existing issues on GitHub
3. Create a new issue with detailed information
