# NATS Messenger Transport - Setup Command Support

## Overview

This NATS Messenger Transport now supports Symfony Messenger's `messenger:setup-transports` command, enabling automatic creation and configuration of NATS JetStream streams.

## Quick Start

1. **Configure your transport** in `config/packages/messenger.yaml`:
```yaml
framework:
    messenger:
        transports:
            async: 'nats-jetstream://admin:password@localhost:4222/my_stream/my_subject'
        routing:
            'App\Message\MyMessage': async
```

2. **Run the setup command**:
```bash
php bin/console messenger:setup-transports
```

3. **Start processing messages**:
```bash
php bin/console messenger:consume async
```

## Features

### ✅ Automatic Stream Creation
The setup command automatically creates NATS JetStream streams if they don't exist.

### ✅ Configurable Stream Options
Control stream behavior via DSN query parameters:
```yaml
async: 'nats-jetstream://user:pass@localhost:4222/orders/new?stream_max_age=3600&stream_replicas=2'
```

### ✅ CI/CD Integration
Add setup to your deployment pipeline:
```bash
php bin/console messenger:setup-transports --no-interaction
```

### ✅ Error Handling
Clear error messages when NATS is unavailable or configuration is invalid.

## Configuration Options

| Parameter | Description | Default | Notes |
|-----------|-------------|---------|-------|
| `stream_max_age` | Maximum message age in seconds | 0 (unlimited) | Converted to nanoseconds internally |
| `stream_max_bytes` | Maximum stream size in bytes | unlimited | |
| `stream_replicas` | Number of stream replicas | 1 | |

**Important**: The `stream_max_age` parameter is specified in seconds in the DSN, but is automatically converted to nanoseconds when configuring the NATS stream (as required by the NATS JetStream API).

## Example Usage

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders: 'nats-jetstream://admin:password@localhost:4222/orders/new'
            notifications: 'nats-jetstream://admin:password@localhost:4222/notifications/email?stream_max_age=86400'
            events: 'nats-jetstream://admin:password@localhost:4222/events/user.actions?stream_replicas=3'

        routing:
            'App\Message\OrderCreated': orders
            'App\Message\EmailNotification': notifications
            'App\Message\UserEvent': events
```

```bash
# Setup all transports
php bin/console messenger:setup-transports

# Setup specific transport
php bin/console messenger:setup-transports orders

# Use in deployment
php bin/console messenger:setup-transports --no-interaction
```

## Requirements

- Symfony Messenger 7.2+
- NATS server with JetStream enabled
- PHP 8.2+

## Implementation Details

The transport implements Symfony's `SetupableTransportInterface`, which:
- Connects to NATS JetStream API
- Checks if the configured stream exists
- Creates the stream with proper configuration if needed
- Applies stream options from DSN parameters
- Provides detailed error messages for troubleshooting

This integration follows Symfony's standard patterns and works seamlessly with existing messenger infrastructure.