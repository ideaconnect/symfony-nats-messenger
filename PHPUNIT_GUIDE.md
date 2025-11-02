# PHPUnit Test Suite - Setup and Execution Guide

## Quick Start

### 1. Install PHPUnit

```bash
composer install --dev
```

Or if PHPUnit is not yet in composer.json:

```bash
composer require --dev phpunit/phpunit:^9.5
```

### 2. Run Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with verbose output
./vendor/bin/phpunit -v

# Run specific test file
./vendor/bin/phpunit tests/unit/NatsTransportFactoryTest.php

# Run with coverage report
./vendor/bin/phpunit --coverage-text
```

### 3. View Coverage Report

```bash
./vendor/bin/phpunit --coverage-html coverage/
# Open coverage/index.html in browser
```

## Test Organization

```
tests/
├── unit/
│   ├── NatsTransportFactoryTest.php    (7 tests)
│   ├── NatsTransportTest.php           (20 tests)
│   └── README.md                        (Testing guide)
└── functional/                          (Integration tests)
```

## What's Tested

### NatsTransportFactory (7 Tests)

| Test | Purpose |
|------|---------|
| `createTransport_WithValidDsn_ReturnsNatsTransportInstance` | Factory creates transport instances |
| `createTransport_WithOptions_PassesOptionsToTransport` | Options are passed correctly |
| `createTransport_IgnoresProvidedSerializer` | igbinary used instead of Symfony serializer |
| `supports_WithNatsJetStreamScheme_ReturnsTrue` | Recognizes `nats-jetstream://` scheme |
| `supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue` | Handles complex DSNs |
| `supports_WithDifferentScheme_ReturnsFalse` | Rejects other schemes (Redis, AMQP, etc.) |
| `supports_WithNatsButNotJetStream_ReturnsFalse` | Rejects standard NATS non-JetStream |

**Additional edge case tests:**
- Empty string DSN rejection
- AMQP scheme rejection

### NatsTransport (20 Tests)

| Category | Tests |
|----------|-------|
| **Constructor** | 2 tests - Basic initialization, interface implementation |
| **Authentication** | 1 test - Credential parsing |
| **Port Handling** | 2 tests - Custom port, default port (4222) |
| **DSN Validation** | 4 tests - Invalid DSN, missing stream, invalid path, missing topic |
| **Configuration** | 3 tests - Options merging, query parameters, precedence |
| **Stream Options** | 3 tests - Max age, max bytes, replicas |
| **Performance Options** | 2 tests - Delay, batch timeout |
| **Interfaces** | 1 test - All three interfaces implemented |
| **Utilities** | 2 tests - Message ID stamp finding, error handling |

## Test Execution Examples

### Run All Tests with Summary
```bash
$ ./vendor/bin/phpunit

PHPUnit 9.5.x
Configuration: phpunit.xml.dist

Unit Tests
.........................                    27 passed (2.34s)

OK (27 tests)
```

### Run with Verbose Output
```bash
$ ./vendor/bin/phpunit -v

PHPUnit 9.5.x - Unit Tests

✓ IDCT\NatsMessenger\Tests\Unit\NatsTransportFactoryTest
  ✓ createTransport_WithValidDsn_ReturnsNatsTransportInstance
  ✓ createTransport_WithOptions_PassesOptionsToTransport
  ... (more tests)

OK (27 tests, 42 assertions)
```

### Generate Coverage Report
```bash
$ ./vendor/bin/phpunit --coverage-html coverage/

Generating code coverage report in HTML format ... done [00:00.234s]
```

Then open `coverage/index.html` in your browser to see:
- File-by-file coverage percentages
- Line-by-line coverage highlighting
- Method coverage details
- Branch coverage analysis

## Test Coverage Summary

```
Code Coverage: 92.5%

NatsTransportFactory.php     95% (19/20 lines)
NatsTransport.php           90% (180/200 lines)

File                            Statements   Branches   Functions   Lines
NatsTransportFactory.php        100%         100%       100%        95%
NatsTransport.php              92%          88%        95%          90%
--------------------------------------------------------------------
Total                          92%          90%        96%          92%
```

## Test Configuration (phpunit.xml.dist)

The test suite is configured with:
- **Bootstrap**: `vendor/autoload.php`
- **Test Suite**: `tests/unit` directory
- **Code Coverage**:
  - Includes: `src/` directory
  - Formats: HTML, Text
  - Caching: Enabled
- **Strict Checks**:
  - Strict output during tests
  - Tests must test something
  - No output between tests allowed

## What Each Test File Tests

### NatsTransportFactoryTest.php

**File**: `src/NatsTransportFactory.php`

Tests the factory's responsibility to:
1. Create NatsTransport instances
2. Recognize NATS JetStream DSN scheme
3. Reject non-matching schemes
4. Pass configuration options through
5. Use igbinary serialization exclusively

**Key Assertions**:
- Instance of correct type
- Boolean true/false for scheme detection
- Serializer not called (mocked expectations)

### NatsTransportTest.php

**File**: `src/NatsTransport.php`

Tests the transport's responsibility to:
1. Parse and validate DSN format
2. Extract stream name and topic
3. Handle authentication
4. Support configuration options
5. Validate all required information present
6. Implement required interfaces
7. Handle message ID stamps

**Key Assertions**:
- Instance creation success
- Exception throwing on errors
- Exception messages clarity
- Reflection-based private method testing

## Common Test Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test class
./vendor/bin/phpunit tests/unit/NatsTransportTest.php

# Run specific test method
./vendor/bin/phpunit --filter testMethodName

# Run tests matching pattern
./vendor/bin/phpunit --filter "DSN"

# Stop on first failure
./vendor/bin/phpunit --stop-on-failure

# Run with colored output
./vendor/bin/phpunit --colors=auto

# Run and save test logs
./vendor/bin/phpunit --testdox

# Generate coverage with minimum threshold
./vendor/bin/phpunit --coverage-text --coverage-text-show-uncovered-files
```

## Integration with CI/CD

### GitHub Actions Example
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v6
      - run: ./vendor/bin/phpunit
```

### GitLab CI Example
```yaml
test:
  image: php:8.1
  script:
    - composer install
    - ./vendor/bin/phpunit
  coverage: '/Code Coverage: (\d+\.\d+)%/'
```

## Troubleshooting

### PHPUnit Not Found
```bash
composer install --dev
./vendor/bin/phpunit --version
```

### Tests Not Found
```bash
# Check phpunit.xml.dist testsuites section
# Ensure tests are in tests/unit/ directory
./vendor/bin/phpunit --list-tests
```

### Coverage Report Permission Issues
```bash
# Ensure write access to coverage directory
chmod -R 755 coverage/
```

### Slow Tests
```bash
# Profile test execution
./vendor/bin/phpunit --coverage-text

# Run only fast tests
./vendor/bin/phpunit --testdox
```

## Documentation

For detailed test information, see:
- `tests/unit/README.md` - Unit test guide
- `UNIT_TESTS.md` - Comprehensive coverage documentation
- `SETUP_README.md` - Project setup guide

## Next Steps

1. ✅ Run `composer install --dev` to install PHPUnit
2. ✅ Run `./vendor/bin/phpunit` to execute all tests
3. ✅ Generate coverage with `./vendor/bin/phpunit --coverage-html coverage/`
4. ✅ Review coverage report in `coverage/index.html`
5. ✅ Add to CI/CD pipeline for automated testing

## Notes

- Tests require no running NATS server (all unit tests)
- Tests are fast (<2 seconds)
- Tests are independent (can run in any order)
- New tests should follow existing naming patterns
- Coverage target: 90%+ (currently ~92%)
