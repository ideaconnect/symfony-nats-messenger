# NATS Messenger Transport Setup Support

This NATS transport now supports Symfony Messenger's `messenger:setup-transports` command, which allows you to create the required NATS JetStream streams for handling messages.

## Setup Command Usage

Once you have configured your NATS transport in `config/packages/messenger.yaml`, you can use the setup command to create the necessary streams:

```bash
# Setup all transports
php bin/console messenger:setup-transports

# Setup a specific transport
php bin/console messenger:setup-transports async
```

## Configuration

### Basic Configuration

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: 'nats-jetstream://admin:password@localhost:4222/my_stream/my_subject'
        routing:
            'App\Message\MyMessage': async
```

### Advanced Configuration with Stream Options

You can configure stream-specific options via DSN query parameters:

```yaml
framework:
    messenger:
        transports:
            async: 'nats-jetstream://admin:password@localhost:4222/my_stream/my_subject?stream_max_age=3600&stream_replicas=3&stream_max_bytes=1048576'
```

#### Available Stream Configuration Options

- `stream_max_age`: Maximum age of messages in seconds (0 = unlimited)
- `stream_max_bytes`: Maximum total size of the stream in bytes
- `stream_replicas`: Number of replicas for the stream (default: 1)

### DSN Format

```
nats-jetstream://[username:password@]host[:port]/stream_name/subject_name[?options]
```

Where:
- `username:password`: NATS authentication credentials (optional)
- `host`: NATS server hostname
- `port`: NATS server port (default: 4222)
- `stream_name`: Name of the JetStream stream to create/use
- `subject_name`: Subject/topic within the stream
- `options`: Query parameters for additional configuration

## What the Setup Command Does

When you run `messenger:setup-transports`, the NATS transport will:

1. **Check if the stream exists**: Uses the NATS JetStream API to check if the configured stream already exists
2. **Create the stream if needed**: If the stream doesn't exist, it creates it with the specified configuration
3. **Configure subjects**: Sets up the stream to handle messages for the specified subject/topic
4. **Apply stream options**: Configures stream settings like max age, max bytes, and replicas based on DSN parameters

## Example Workflow

1. Configure your transport in `messenger.yaml`:
   ```yaml
   framework:
       messenger:
           transports:
               notifications: 'nats-jetstream://admin:password@localhost:4222/notifications/email.send'
   ```

2. Run the setup command:
   ```bash
   php bin/console messenger:setup-transports notifications
   ```

3. The command will create a stream named "notifications" configured to handle messages for the "email.send" subject

4. Your application is now ready to send and receive messages via the NATS transport

## Error Handling

If the setup fails (e.g., NATS server is unreachable, insufficient permissions), the command will display a clear error message with details about what went wrong.

## Integration with CI/CD

You can include the setup command in your deployment process to ensure streams are created automatically:

```bash
# In your deployment script
php bin/console messenger:setup-transports --no-interaction
```

This ensures that all required NATS streams are properly configured before your application starts processing messages.