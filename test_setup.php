<?php

require_once __DIR__ . '/vendor/autoload.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

echo "Testing NATS Transport Setup Functionality\n";
echo "==========================================\n\n";

// Test DSN - matches the one in messenger.yaml
$dsn = 'nats-jetstream://admin:password@localhost:4222/stream/messages';

try {
    echo "1. Creating NatsTransport with DSN: $dsn\n";
    $transport = new NatsTransport($dsn);

    echo "2. Checking if transport implements SetupableTransportInterface...\n";
    if ($transport instanceof SetupableTransportInterface) {
        echo "   ✓ Transport implements SetupableTransportInterface\n";
    } else {
        echo "   ✗ Transport does NOT implement SetupableTransportInterface\n";
        exit(1);
    }

    echo "3. Calling setup() method...\n";
    $transport->setup();
    echo "   ✓ Setup completed successfully!\n";

    echo "\n✓ All tests passed! The stream should now be created in NATS.\n";
    echo "  You can now use the 'messenger:setup-transports' command in Symfony.\n";

} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}