# Symfony NATS Messenger Bridge

[![PHP Version](https://img.shields.io/badge/PHP-^8.1-787CB5?logo=php&logoColor=white)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/Symfony-^7.2-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Unit Tests Coverage](https://img.shields.io/badge/Coverage-95.97%25-brightgreen)](https://github.com/ideaconnect/symfony-nats-messenger/actions)
[![Functional Tests](https://img.shields.io/badge/Functional%20Tests-Behat-blue)](tests/functional)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![CI](https://github.com/ideaconnect/symfony-nats-messenger/actions/workflows/ci.yml/badge.svg)](https://github.com/ideaconnect/symfony-nats-messenger/actions/workflows/ci.yml)

A Symfony Messenger transport integration for [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream), enabling reliable asynchronous messaging with persistent message streaming.

## Features

- 🚀 **High-Performance Messaging** - Leverage NATS JetStream for fast, reliable message delivery
- 📦 **Symfony Integration** - Seamless integration with Symfony Messenger
- ⚙️ **Configurable Consumers** - Support for multiple consumer strategies
- 🔄 **Flexible Batching** - Adjustable message batch sizes and timeouts
- 🔐 **Authentication Support** - Built-in support for NATS authentication
- 📊 **Stream Configuration** - Configurable retention policies and replication
- 🧪 **Thoroughly Tested** - 102 unit tests with ~96% code coverage

## Requirements

### System Requirements
- **PHP**: ^8.1
- **Symfony**: ^7.2
- **NATS Server**: ^2.9 with JetStream enabled

### PHP Dependencies
- `symfony/framework-bundle`: ^7.2
- `symfony/messenger`: ^7.2
- `symfony/uid`: ^7.2
- `basis-company/nats`: ^1

### Optional
- `phpunit/phpunit`: ^9.5 (for running tests)

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

### 3. Configure Custom Serializers (Optional)

By default, the transport uses `igbinary` serialization for high performance. You can customize this:

#### Using IgbinarySerializer (Default)

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

**Note:** Serializers are not created during execution of the transport. They need to be previously registered services.

For example:
```yaml
    igbinary_serializer:
        class: IDCT\NatsMessenger\Serializer\IgbinarySerializer
```

or:
```yaml
    IDCT\NatsMessenger\Serializer\IgbinarySerializer: ~
```

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

For reference implementations, see:
- `src/Serializer/IgbinarySerializer.php` - Binary serialization
- `src/Serializer/AbstractEnveloperSerializer.php` - Base class

### 4. Send Messages

```php
use App\Message\MyAsyncMessage;
use Symfony\Component\Messenger\MessageBus;

class MyController
{
    public function __construct(private MessageBus $bus) {}

    public function send(): void
    {
        $this->bus->dispatch(new MyAsyncMessage('Hello NATS!'));
    }
}
```

### 5. Handle Messages

```php
use App\Message\MyAsyncMessage;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class MyAsyncMessageHandler implements MessageHandlerInterface
{
    public function __invoke(MyAsyncMessage $message): void
    {
        echo "Processing: " . $message->getText();
    }
}
```

### 6. Consume Messages

```bash
symfony console messenger:consume nats_transport
```

## Configuration Guide

### DSN Format

```
nats-jetstream://[user:password@]host:port/stream-name/topic-name
```

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
          connection_timeout: 1.0           # Socket I/O timeout in seconds (default: 1)

          # Stream Retention Policies
          stream_max_age: 86400             # Max message age in seconds (0 = unlimited, default: 0)
          stream_max_bytes: 1073741824      # Max storage size in bytes (null = unlimited)
          stream_max_messages: 1000000      # Max number of messages (null = unlimited)

          # High Availability
          stream_replicas: 1                # Number of replicas (default: 1)

          # Failure Handling Strategy
          retry_handler: 'symfony'          # symfony|nats (default: symfony)
                                            # symfony => TERM on failed/rejected message
                                            # nats    => NAK on failed/rejected message

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

### Retry Handler Behavior

- `retry_handler: symfony` (default) sends `TERM` when a message fails during transport decoding or is rejected.
- `retry_handler: nats` sends `NAK` when a message fails during transport decoding or is rejected.

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

### Batch Timeout

Controls how long to wait for a batch to fill:

```yaml
options:
  batching: 10
  max_batch_timeout: 0.5  # Wait max 0.5s for batch to fill
                          # Returns early if timeout reached
```

**Example scenarios:**
- If you set `batching: 10` and `max_batch_timeout: 0.5`
- If 10 messages arrive quickly, all are fetched immediately
- If only 3 messages arrive in 0.5s, return those 3

### Connection Timeout

Controls the socket-level I/O timeout for all NATS operations:

```yaml
options:
  connection_timeout: 2.0  # Socket timeout in seconds
```

**Purpose:**
- Sets the timeout for socket read/write operations
- Affects all NATS communication (publish, subscribe, ack, etc.)
- Lower values fail faster on network issues
- Higher values tolerate slower networks

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

  # By message count (1 million messages)
  stream_max_messages: 1000000

  # Unlimited (default)
  stream_max_age: 0
  stream_max_bytes: null
  stream_max_messages: null
```

### High Availability

```yaml
options:
  # Single replica (no redundancy)
  stream_replicas: 1

  # 3 replicas (recommended for production)
  stream_replicas: 3
```

## Testing

### Unit Tests

```bash
# Install dependencies
composer install --dev

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
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;

// Get message count
$count = $transport->getMessageCount();

// Check if messages are pending
if ($count > 0) {
    echo "Pending messages: $count";
}
```

## Troubleshooting

### Connection Issues

**Error: "Connection refused"**
```bash
# Check NATS is running
nats-server --js

# Verify host and port
nats-jetstream://localhost:4222/stream/topic
```

**Error: "Stream not found"**
```bash
# Run setup command to create stream
symfony console messenger:setup-transports nats_transport
```

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
- Implements Symfony's `TransportInterface`
- Manages stream and consumer connections
- Handles message serialization (igbinary)
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

The default `IgbinarySerializer` (and any serializer extending `AbstractEnveloperSerializer`) deserializes raw message payloads from NATS into PHP objects. PHP object unserialization is a [well-known attack vector](https://owasp.org/Top10/A08_2021-Software_and_Data_Integrity_Failures/) — a crafted payload can trigger arbitrary code execution via magic methods (`__wakeup`, `__destruct`, etc.).

**If your NATS topics are not fully trusted** (e.g. shared infrastructure, external publishers), you should:
- Implement a custom serializer that uses a safe format (JSON, Protobuf) instead of PHP object serialization
- Add message-level authentication (e.g. HMAC signatures) to verify publisher identity before deserializing
- Restrict NATS topic publish permissions via ACLs so only trusted services can publish

The type check (`instanceof Envelope`) happens *after* deserialization, which is too late to prevent exploitation.

### ⚠️ Stream-Exists Detection Is Fragile

During `setup()`, the transport detects whether a stream already exists by matching error message strings from the NATS server (e.g. `"already in use"`, `"already exists"`). This is brittle and may break if the NATS server changes its error wording in a future release. Additionally, HTTP status code 400 is treated as "stream exists", but 400 can indicate other bad-request errors.

If you experience unexpected behavior during stream setup, check that your NATS server version is compatible and review the error messages returned by the server.

### ⚠️ Silent Publish Failures on Non-JSON Responses

The transport validates JetStream publish responses by parsing them as JSON and checking for an `error` field. If the server returns a non-JSON response (e.g. due to a proxy, misconfiguration, or protocol error), the validation silently passes — a failed publish could go unnoticed.

Monitor your NATS server logs and consider implementing application-level publish confirmation if delivery guarantees are critical.

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

# 💖 Love the project? Support it! 🚀

* 🪙 **BTC**: bc1qntms755swm3nplsjpllvx92u8wdzrvs474a0hr
* 💎 **ETH**: 0x08E27250c91540911eD27F161572aFA53Ca24C0a
* ⚡ **TRX**: TVXWaU4ScNV9RBYX5RqFmySuB4zF991QaE
* 🚀 **LTC**: LN5ApP1Yhk4iU9Bo1tLU8eHX39zDzzyZxB
* ☕ **Buy me a coffee**: https://buymeacoffee.com/idct
* 💝 **Sponsor**: https://github.com/sponsors/ideaconnect
