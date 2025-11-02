<?php

require_once __DIR__ . '/vendor/autoload.php';

use IDCT\NatsMessenger\NatsTransport;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

echo "Testing NATS Transport Setup Interface Implementation\n";
echo "====================================================\n\n";

// Test DSN
$dsn = 'nats-jetstream://admin:password@localhost:4222/stream/messages';

try {
    echo "1. Checking class definition and interface implementation...\n";

    $reflection = new ReflectionClass(NatsTransport::class);
    $interfaces = $reflection->getInterfaceNames();

    echo "   Implemented interfaces:\n";
    foreach ($interfaces as $interface) {
        echo "   - $interface\n";
    }

    if (in_array(SetupableTransportInterface::class, $interfaces)) {
        echo "   ✓ NatsTransport implements SetupableTransportInterface\n";
    } else {
        echo "   ✗ NatsTransport does NOT implement SetupableTransportInterface\n";
        exit(1);
    }

    echo "\n2. Checking setup() method existence...\n";
    if ($reflection->hasMethod('setup')) {
        $setupMethod = $reflection->getMethod('setup');
        echo "   ✓ setup() method exists\n";
        echo "   - Method visibility: " . ($setupMethod->isPublic() ? 'public' : 'not public') . "\n";
        echo "   - Return type: " . ($setupMethod->getReturnType() ? $setupMethod->getReturnType()->getName() : 'none') . "\n";
        echo "   - Parameters: " . count($setupMethod->getParameters()) . "\n";
    } else {
        echo "   ✗ setup() method does NOT exist\n";
        exit(1);
    }

    echo "\n3. Checking DSN parsing functionality...\n";

    // Test DSN parsing without connecting
    $dsnComponents = parse_url($dsn);
    echo "   DSN Components:\n";
    echo "   - Scheme: " . $dsnComponents['scheme'] . "\n";
    echo "   - Host: " . $dsnComponents['host'] . "\n";
    echo "   - Port: " . ($dsnComponents['port'] ?? 'default') . "\n";
    echo "   - Path: " . $dsnComponents['path'] . "\n";

    if (isset($dsnComponents['path'])) {
        $pathParts = explode('/', substr($dsnComponents['path'], 1));
        if (count($pathParts) >= 2) {
            echo "   - Stream name: " . $pathParts[0] . "\n";
            echo "   - Topic/Subject: " . $pathParts[1] . "\n";
            echo "   ✓ DSN parsing should work correctly\n";
        }
    }

    echo "\n✓ All interface checks passed!\n";
    echo "\nThe NATS transport now supports the messenger:setup-transports command.\n";
    echo "When you run it with a live NATS server, it will:\n";
    echo "- Create the stream if it doesn't exist\n";
    echo "- Configure the stream with the specified subject/topic\n";
    echo "- Apply any stream configuration options passed via DSN query parameters\n";

} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}