# NATS Messenger Transport - Functional Tests

This directory contains functional tests using Behat that verify the NATS transport setup functionality with a real NATS server.

## Prerequisites

- Docker (for running NATS server)
- Composer dependencies installed (`composer install`)
- PHP 8.2+

## Running the Tests

### 1. Install Dependencies

```bash
cd tests/functional
composer install
```

### 2. Run All Tests

```bash
# Run all functional tests
vendor/bin/behat

# Run tests with verbose output
vendor/bin/behat -v
```

### 3. Run Specific Scenarios

```bash
# Run a specific scenario by line number
vendor/bin/behat features/nats_setup.feature:9

# Run all scenarios in a feature
vendor/bin/behat features/nats_setup.feature
```

### 4. Dry Run (Parse Only)

```bash
# Check if tests are properly configured without running them
vendor/bin/behat --dry-run
```

## Performance Benchmark

The functional test suite includes a comprehensive performance benchmark to measure throughput and memory usage:

### Quick Start

```bash
# Run the benchmark with 1,000,000 messages
./run-benchmark.sh

# See all options
./run-benchmark.sh --help
```

### What the Benchmark Tests

- **1,000,000 messages** sent and received
- **Multiple batch configurations**: 1, 100, 1,000, 10,000, 1,000,000
- **Metrics collected**:
  - Total time (seconds)
  - Memory used (MB)
  - Peak memory (MB)
  - Throughput (messages/second)

### Example Benchmark Commands

```bash
# Full benchmark with defaults
./run-benchmark.sh

# Custom message count (for faster testing)
./run-benchmark.sh --count 100000

# Specific batch sizes
./run-benchmark.sh --batches "1,50,500,5000"

# Only test sending (skip consumption)
./run-benchmark.sh --skip-consume

# Only test consumption (skip sending)
./run-benchmark.sh --skip-send

# Combined: 500K messages, custom batches, skip sending
./run-benchmark.sh --count 500000 --batches "1,100,1000" --skip-send
```

### Expected Output

The benchmark produces a comparison table:

```
┌───────────────────┬──────────────┬──────────────┬────────────┬──────────────┬────────────────┬──────────────────┐
│ Phase             │ Batch Size   │ Messages     │ Total Time │ Memory Used  │ Peak Memory    │ Throughput       │
├───────────────────┼──────────────┼──────────────┼────────────┼──────────────┼────────────────┼──────────────────┤
│ SEND              │ 1            │ 1,000,000    │ 12.34 s    │ 45.20 MB     │ 128.50 MB      │ 81,038.52 msg/s  │
│ CONSUME (batch=1) │ 1            │ 1,000,000    │ 8.56 s     │ 32.10 MB     │ 95.20 MB       │ 116,822.43 msg/s │
│ CONSUME (batch=100) │ 100        │ 1,000,000    │ 7.21 s     │ 28.50 MB     │ 87.30 MB       │ 138,697.11 msg/s │
└───────────────────┴──────────────┴──────────────┴────────────┴──────────────┴────────────────┴──────────────────┘
```

### Benchmark Documentation

For detailed information on the benchmark suite, see:
- [BENCHMARK.md](./BENCHMARK.md) - Comprehensive benchmark documentation
- [BENCHMARK_QUICK_REFERENCE.md](./BENCHMARK_QUICK_REFERENCE.md) - Quick reference guide

Key components:
- `BenchmarkMessage.php` - Lightweight message for testing
- `BenchmarkMessageHandler.php` - Zero-work handler
- `BenchmarkMetrics.php` - Metrics collection and formatting
- `BenchmarkMessengerCommand.php` - Main benchmark command
- `run-benchmark.sh` - Executable benchmark script

## Test Scenarios

The functional tests cover three main scenarios:

### 1. **Setup NATS stream with max age configuration**
- ✅ Starts NATS server automatically
- ✅ Configures messenger transport with 15-minute max age
- ✅ Runs `messenger:setup-transports` command
- ✅ Verifies stream is created successfully
- ✅ Validates stream has correct max age (15 minutes = 900,000,000,000 nanoseconds)
- ✅ Confirms stream is configured with correct subject

### 2. **Setup command handles existing streams gracefully**
- ✅ Creates stream manually before running setup
- ✅ Runs setup command on existing stream
- ✅ Verifies command completes successfully
- ✅ Confirms existing configuration is preserved

### 3. **Setup command fails gracefully when NATS is unavailable**
- ✅ Ensures NATS server is stopped
- ✅ Attempts to run setup command
- ✅ Verifies command fails with connection error
- ✅ Validates error message is descriptive

## Test Architecture

### Docker Integration
- Tests automatically start/stop NATS server using Docker Compose
- Uses isolated container (`nats_test`) to avoid conflicts
- NATS runs with JetStream enabled and authentication configured

### Configuration Management
- Creates temporary messenger configuration files for each test
- Uses test environment (`--env=test`) to isolate from development config
- Automatically cleans up configuration files after tests

### Stream Management
- Uses test-specific stream names (`test_stream`) to avoid conflicts
- Automatically deletes test streams after each scenario
- Handles both creation and cleanup of NATS resources

## Configuration Files

### `behat.yml`
Main Behat configuration file defining test contexts and formatters.

### `features/nats_setup.feature`
Gherkin feature file describing test scenarios in human-readable format.

### `tests/Behat/NatsSetupContext.php`
PHP context class implementing the test step definitions.

### `nats/docker-compose.yaml`
Docker Compose configuration for NATS server with JetStream.

### `nats/nats.conf`
NATS server configuration with authentication and JetStream enabled.

## Debugging Tests

### View Test Output
```bash
# Run with maximum verbosity
vendor/bin/behat -vvv

# Show step definitions
vendor/bin/behat --definitions
```

### Manual Testing
```bash
# Start NATS manually
cd nats && docker compose up -d

# Test setup command manually
php bin/console messenger:setup-transports test_transport --env=test

# Check NATS logs
cd nats && docker compose logs nats
```

### Check NATS Status
```bash
# Verify NATS is running
docker ps | grep nats_test

# Access NATS monitoring (if needed)
curl http://localhost:8223/varz
```

## Error Handling

The tests include comprehensive error handling for common issues:

- **Docker not available**: Tests will fail with clear message
- **Ports already in use**: Docker Compose uses alternative ports (6223, 8223)
- **NATS connection failures**: Tests verify error messages are descriptive
- **Configuration errors**: Temporary config files are validated

## Cleanup

Tests automatically clean up after themselves:
- Docker containers are stopped
- Test streams are deleted
- Temporary configuration files are removed

To manually clean up if tests are interrupted:
```bash
cd nats && docker compose down
rm -f config/packages/test_messenger.yaml
```

## Integration with CI/CD

These tests can be integrated into CI/CD pipelines:

```bash
# In your CI script
cd tests/functional
composer install --no-dev --optimize-autoloader
vendor/bin/behat --no-interaction
```

Ensure Docker is available in your CI environment for the tests to run successfully.