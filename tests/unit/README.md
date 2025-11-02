# Unit Tests

Comprehensive unit tests for the NATS Messenger Bridge components.

## Structure

- `NatsTransportFactoryTest.php` - Tests for the transport factory class
- `NatsTransportTest.php` - Tests for the main transport class

## Running Tests

### Install PHPUnit (if not already installed)

```bash
composer require --dev phpunit/phpunit:^9.5
```

### Run All Tests

```bash
./vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Run only factory tests
./vendor/bin/phpunit tests/unit/NatsTransportFactoryTest.php

# Run only transport tests
./vendor/bin/phpunit tests/unit/NatsTransportTest.php
```

### Run With Coverage Report

```bash
./vendor/bin/phpunit --coverage-html coverage/
```

This generates an HTML coverage report in the `coverage/` directory.

## Test Coverage

### NatsTransportFactory Tests

- ✅ Creating transport instances with valid DSN
- ✅ Passing options to created transport
- ✅ Ignoring Symfony serializer in favor of igbinary
- ✅ Supporting NATS JetStream DSN scheme
- ✅ Rejecting other DSN schemes (Redis, AMQP, etc.)
- ✅ Handling edge cases (empty DSN, wrong scheme)

### NatsTransport Tests

- ✅ Constructor initialization with valid DSN
- ✅ DSN validation and error handling
- ✅ Missing stream name detection
- ✅ Invalid path format detection
- ✅ Authentication parsing
- ✅ Custom port parsing
- ✅ Default port (4222) usage
- ✅ Query parameter parsing
- ✅ Configuration option precedence
- ✅ Interface implementations
- ✅ Transport message ID stamp handling
- ✅ Stream configuration options (max age, max bytes, replicas)
- ✅ Performance settings (delay, batch timeout)

## Key Testing Patterns

### DSN Validation Tests

Tests ensure that DSN parsing correctly:
- Validates format and presence of required components
- Extracts stream name and topic
- Parses authentication credentials
- Handles custom and default ports
- Merges query parameters with options

### Configuration Tests

Tests verify that:
- Configuration options are properly accepted
- Query parameters are parsed
- Constructor options take precedence
- Default values are used when not specified

### Error Handling Tests

Tests confirm that exceptions are thrown for:
- Invalid DSN format
- Missing stream name
- Missing topic
- Invalid paths

## Notes

- Tests do not require a running NATS server
- Tests focus on configuration parsing and transport instantiation
- Integration tests are located in `tests/functional/`
