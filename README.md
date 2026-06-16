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

> **Tested by:** `createTransport_UsesProvidedSerializer`, `serialize_WithValidEnvelope_ReturnsSerializedString`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `testConstructorWithoutIgbinaryDoesNotCrash`

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

          # Stream Retention Policies
          stream_max_age: 86400             # Max message age in seconds (0 = unlimited, default: 0)
          stream_max_bytes: 1073741824      # Max storage size in bytes (null = unlimited)
          stream_max_messages: 1000000      # Max number of messages in the stream (null = unlimited)
          stream_max_messages_per_subject: 1000 # Max number of messages retained per subject (null = unlimited)

          # Storage Backend
          stream_storage: 'file'            # Storage type: 'file' or 'memory' (default: 'file')

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

          # TLS Configuration
          tls_required: false               # Force TLS for NATS connection (default: false)
          tls_handshake_first: false        # Use TLS-first handshake mode (default: false)
          tls_ca_file: null                 # Path to CA certificate file
          tls_cert_file: null               # Path to client certificate file
          tls_key_file: null                # Path to client private key
          tls_key_passphrase: null          # Passphrase for encrypted private key
          tls_peer_name: null               # Override TLS peer name for certificate validation
          tls_verify_peer: true             # Verify TLS peer certificate (default: true)

          # Additional Authentication
          token: null                       # NATS token authentication
          username: null                    # Overrides DSN username if provided
          password: null                    # Overrides DSN password if provided
          jwt: null                         # JWT authentication value
          nkey: null                        # NKey public value
```

> **Tested by:** `testReadmeConfigurationOptionsAreAccepted` (all options above), `testReadmeBatchingExamplesAreAccepted`, `testReadmeTimeoutExamplesAreAccepted`, `testReadmeStreamRetentionExamplesAreAccepted`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`

### Retry Handler Behavior

- `retry_handler: symfony` (default) sends `TERM` when a message fails during transport decoding or is rejected. Symfony's retry/failure transport then handles redelivery.
- `retry_handler: nats` sends `NAK` when a message fails during transport decoding or is rejected, so NATS redelivers the message itself.

When NATS manages redelivery (`retry_handler: nats`), tune it with `nak_delay`, `ack_wait`, `max_deliver`, and `backoff`:

- **`nak_delay`** delays each NAK so a failing message backs off instead of redelivering immediately (a hot loop).
- **`max_deliver`** caps redeliveries. ⚠️ Without it, `retry_handler: nats` redelivers a permanently-failing ("poison") message **forever** - set `max_deliver` in production.
- **`backoff`** sets an escalating per-attempt delay schedule (e.g. `[1, 5, 30]` seconds); pair it with `max_deliver` greater than the number of backoff steps.
- **`ack_wait`** is how long JetStream waits for an ACK before considering a delivery failed and redelivering - raise it for handlers that legitimately take a while.

> **Tested by:** `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesNakWithDelayWhenConfigured`, `testSetupAppliesConsumerRetryTuning`, `testBuildAcceptsNatsRetryTuningOptions`, `testBuildUsesRetryHandlerFromQuery`, Behat scenarios `nats_nak.feature` and `nats_term.feature`

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

Controls the timeout for establishing the NATS connection (initial and reconnect dial attempts):

```yaml
options:
  connection_timeout: 2.0  # Connection (dial) timeout in seconds
```

> **Tested by:** `testReadmeTimeoutExamplesAreAccepted` (1.0, 2.0, 3.0), `testBuildWithConnectionTimeoutPropagatesMs`

**Purpose:**
- Sets the timeout for the initial TCP/TLS dial and handshake when connecting to NATS
- Does **not** govern per-operation read/write timeouts (publish/ack/request keep the client's own request timeout); the batch fetch is bounded separately by `max_batch_timeout`
- Lower values fail faster on connection issues
- Higher values tolerate slower connection establishment

**When to adjust:**
- Increase for high-latency networks or geographically distant NATS servers
- Decrease for faster failure detection in local environments
- Default of 1 second works well for most local/regional deployments
- Don't wait forever for the batch to fill

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

### Setup on Initialization

Automatically create streams and consumers on first run:

```yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost/my-stream/my-topic'
        options:
          consumer: 'my-consumer'
```

Then call setup command:

```bash
symfony console messenger:setup-transports nats_transport
```

> **Tested by:** `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenarios `Setup NATS stream with max age configuration`, `Setup command handles existing streams gracefully`, and `Custom consumer name is registered in JetStream`

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

When `scheduled_messages` is disabled (the default), any `DelayStamp` on the envelope is silently ignored and messages are published immediately.

This will:
1. Create the stream with configured settings
2. Create the consumer with explicit ACK policy
3. Verify consumer creation

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

> **Tested by:** `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending`

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
