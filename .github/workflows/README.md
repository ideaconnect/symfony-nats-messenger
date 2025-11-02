# GitHub Actions CI Workflow

This workflow provides comprehensive testing for the Symfony NATS Messenger transport library.

## Workflow Steps

### 1. **Environment Setup**
- Runs on Ubuntu Latest with PHP 8.2, 8.3, and 8.4
- Installs required PHP extensions: `igbinary`, `json`, `mbstring`
- Sets up Xdebug for code coverage
- Installs system dependencies: `bc`, `netcat-openbsd`

### 2. **NATS Server Setup**
- Starts NATS server using Docker Compose from `tests/functional/nats/`
- Waits for NATS to be ready on port 4222
- Verifies server is responding on monitoring port 8223
- Provides detailed logging for troubleshooting

### 3. **Unit Tests**
- Runs PHPUnit tests with Xdebug coverage
- Generates clover.xml coverage report
- Outputs coverage statistics to console

### 4. **Coverage Verification**
- Extracts coverage percentage from clover.xml
- **Enforces minimum 90% line coverage threshold**
- Fails the workflow if coverage is below 90%
- Shows detailed coverage statistics

### 5. **Functional Tests**
- Installs functional test dependencies
- Sets up test environment with NATS connection
- Runs Behat functional tests in non-interactive mode

### 6. **Benchmark Tests**
- Runs performance benchmarks with 1000 messages (CI-optimized)
- Tests various batching configurations
- Continues workflow even if benchmark fails
- Generates benchmark reports

### 7. **Artifact Collection**
- Uploads coverage reports (HTML and XML)
- Uploads benchmark results
- Only uploads artifacts for PHP 8.4 builds
- Retains artifacts for 5 days

### 8. **Cleanup & Debugging**
- Stops NATS server and cleans up containers
- Collects logs on failure for debugging
- Shows container status and network information

## Triggers

- **Push** to `main` or `road_to_stable` branches
- **Pull Requests** to `main` or `road_to_stable` branches

## Coverage Requirements

The workflow **will fail** if:
- Unit test coverage falls below **90%**
- Unit tests fail
- Functional tests fail
- Coverage extraction fails

The workflow **will continue** if:
- Benchmark tests fail (marked as `continue-on-error`)

## Environment Variables

- `APP_ENV=test` - Sets application environment for testing
- `NATS_DSN=nats://localhost:4222` - NATS connection string for tests

## Debugging Failed Runs

If the workflow fails, check:
1. **Coverage logs** - Shows exact coverage percentage and requirements
2. **NATS container logs** - Available in failure collection step
3. **Docker container status** - Shows if NATS started correctly
4. **Network connectivity** - Verifies port 4222 accessibility

## Local Testing

To run similar tests locally:

```bash
# Start NATS
cd tests/functional/nats && docker-compose up -d

# Run unit tests with coverage
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-clover clover.xml

# Check coverage
php -r "
\$xml = simplexml_load_file('clover.xml');
\$metrics = \$xml->project->metrics;
\$coverage = (\$metrics['coveredstatements'] / \$metrics['statements']) * 100;
echo 'Coverage: ' . number_format(\$coverage, 2) . '%' . PHP_EOL;
"

# Run functional tests
cd tests/functional && ./vendor/bin/behat

# Run benchmark
cd tests/functional && ./run-benchmark.sh --count 1000
```