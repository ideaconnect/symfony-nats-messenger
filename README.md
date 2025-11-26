# Symfony NATS Messenger Bridge

[![PHP Version](https://img.shields.io/badge/PHP-^8.1-787CB5?logo=php&logoColor=white)](https://php.net)
[![Symfony Version](https://img.shields.io/badge/Symfony-^7.2-000000?logo=symfony&logoColor=white)](https://symfony.com)
[![Unit Tests Coverage](https://img.shields.io/badge/Coverage-96.15%25-brightgreen)](https://github.com/ideaconnect/symfony-nats-messenger/actions)
[![Functional Tests](https://img.shields.io/badge/Functional%20Tests-Behat-blue)](tests/functional)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![CI Status](https://github.com/ideaconnect/symfony-nats-messenger/workflows/CI/badge.svg?branch=main)](https://github.com/ideaconnect/symfony-nats-messenger/actions)

A Symfony Messenger transport integration for [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream), enabling reliable asynchronous messaging with persistent message streaming.

## Features

- 🚀 **High-Performance Messaging** - Leverage NATS JetStream for fast, reliable message delivery
- 📦 **Symfony Integration** - Seamless integration with Symfony Messenger
- ⚙️ **Configurable Consumers** - Support for multiple consumer strategies
- 🔄 **Flexible Batching** - Adjustable message batch sizes and timeouts
- 🔐 **Authentication Support** - Built-in support for NATS authentication
- 📊 **Stream Configuration** - Configurable retention policies and replication
- 🧪 **Thoroughly Tested** - 28 unit tests with ~92% code coverage

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

# Start NATS server for testing
make run-nats

# Run unit tests with coverage
make run-unit-tests

# Set up functional tests
make setup-functional-tests

# Run functional tests
make run-functional-tests

# Stop NATS server
make stop-nats
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
          max_batch_timeout: 1.0            # Timeout in seconds for batches (default: 0.5)
          delay: 0.01                       # Delay between fetch attempts in seconds (default: 0.01)

          # Stream Retention Policies
          stream_max_age: 86400             # Max message age in seconds (0 = unlimited, default: 0)
          stream_max_bytes: 1073741824      # Max storage size in bytes (null = unlimited)
          stream_max_messages: 1000000      # Max number of messages (null = unlimited)

          # High Availability
          stream_replicas: 1                # Number of replicas (default: 1)
```

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

# Run Nats
make run-nats

# Run all unit tests with coverage (recommended)
make run-unit-tests

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
make setup-functional-tests

# Start NATS server in Docker
make run-nats

# Run functional tests
make run-functional-tests

# Stop NATS server
make stop-nats
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
          delay: 0.001

      # Bulk processing, high throughput
      nats_bulk:
        dsn: 'nats-jetstream://localhost/bulk-stream/bulk-topic'
        options:
          consumer: 'bulk-consumer'
          batching: 50
          delay: 0.05

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

3. **Configure fetch delay**
   - Lower delay (0.001) for low-latency scenarios
   - Higher delay (0.05) to reduce CPU usage

4. **Use appropriate replicas**
   - `stream_replicas: 1` for development
   - `stream_replicas: 3` for production

5. **Monitor performance**
   - Use `getMessageCount()` to track queue depth
   - Monitor handler execution time
   - Watch for stuck messages

## Security Considerations

1. **Authentication**
   - Use credentials in DSN for production: `nats-jetstream://user:password@host/stream/topic`
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
- All tests pass: `make run-unit-tests`
- Code coverage remains above 90%
- New features include corresponding tests
- Documentation is updated
- Functional tests pass: `make run-functional-tests` (if applicable)

### Quick Development Workflow

```bash
# 1. Run unit tests
make run-unit-tests

# 2. Set up functional tests (first time only)
make setup-functional-tests

# 3. Start NATS for functional tests
make run-nats

# 4. Run functional tests
make run-functional-tests

# 5. Clean up
make stop-nats
```

## License

MIT License - see LICENSE file for details

## Support

For issues, questions, or suggestions:
1. Check the [troubleshooting](#troubleshooting) section
2. Check existing issues on GitHub
3. Create a new issue with detailed information
