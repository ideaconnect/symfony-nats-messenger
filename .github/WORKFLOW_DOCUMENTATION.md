# GitHub Actions Workflow Documentation

## Overview

This documentation covers the automated testing and benchmarking workflow for the NATS Messenger Bridge project. The workflow runs on every push and pull request to ensure code quality and performance standards are maintained.

## Workflow File

**Location**: `.github/workflows/tests-and-benchmarks.yml`

## Workflow Structure

The workflow consists of 5 independent jobs that run in parallel:

### 1. Unit Tests (`unit-tests`)

**Purpose**: Run PHP unit tests and verify code coverage

**Configuration**:
- **Runs on**: Ubuntu latest
- **PHP Versions**: 8.1, 8.2 (matrix testing)
- **Coverage Tool**: XDebug

**Steps**:
1. Checkout code
2. Setup PHP with specified version
3. Cache Composer dependencies
4. Install dependencies
5. Run PHPUnit tests with coverage report
6. Verify coverage >= 90% (overall)
7. Verify 100% coverage for public functions

**Coverage Requirements**:
- **Overall**: Minimum 90%
- **Public Functions**: 100% coverage

**Output**:
- Test results
- Coverage percentage
- List of public functions not fully covered

**Triggers Failure If**:
- Overall coverage < 90%
- Tests fail

### 2. Functional Tests (`functional-tests`)

**Purpose**: Run Behat functional tests with real NATS server

**Configuration**:
- **Runs on**: Ubuntu latest
- **PHP Version**: 8.2
- **Services**: NATS server (latest image)

**NATS Service Configuration**:
```yaml
image: nats:latest
ports:
  - 4222:4222    # Main server
  - 8222:8222    # Monitoring
health-checks: enabled
```

**Steps**:
1. Checkout code
2. Setup PHP
3. Cache Composer dependencies
4. Install root dependencies
5. Install functional test dependencies
6. Run Behat tests

**Environment Variables**:
- `NATS_SERVER`: localhost:4222

**Test Locations**:
- `tests/functional/features/*.feature`

**Triggers Failure If**:
- Any Behat scenario fails

### 3. Performance Benchmark (`benchmark`)

**Purpose**: Run performance benchmarks with 1M+ messages

**Configuration**:
- **Runs on**: Ubuntu latest
- **PHP Version**: 8.2
- **Services**: NATS server (latest image)
- **Message Count**: 50,000 (CI optimized)
- **Batch Sizes**: 1, 100, 1,000

**Steps**:
1. Checkout code
2. Setup PHP
3. Cache Composer dependencies
4. Install root dependencies
5. Install functional test dependencies
6. Run benchmark with smaller dataset (50K messages)
7. Upload results as artifact

**Benchmark Command**:
```bash
./run-benchmark.sh --count 50000 --batches "1,100,1000"
```

**Artifacts**:
- Benchmark results stored for 30 days
- Can be downloaded from GitHub Actions

**Note**: Uses 50K messages instead of 1M for CI speed (adjustable in workflow)

### 4. Code Quality Checks (`code-quality`)

**Purpose**: Static analysis and code style verification

**Configuration**:
- **Runs on**: Ubuntu latest
- **PHP Version**: 8.2
- **Tools**:
  - PHPStan (Level 9)
  - PHP-CS-Fixer

**Steps**:
1. Checkout code
2. Setup PHP with tools
3. Cache Composer dependencies
4. Install dependencies
5. Run PHPStan analysis (level 9)
6. Check PHP code style

**Note**: Quality checks don't block the build (failures are non-blocking)

### 5. Test Summary (`summary`)

**Purpose**: Aggregate and report results

**Depends On**: All other jobs

**Steps**:
- Displays summary of all job results
- Reports failures if critical jobs failed
- Blocks if unit tests or functional tests failed

## Running the Workflow

### Manual Trigger

To run the workflow manually (if enabled):

```bash
gh workflow run tests-and-benchmarks.yml
```

### Automatic Triggers

The workflow runs automatically on:

**Push**:
- Branches: `main`, `develop`, `road_to_stable`

**Pull Requests**:
- Target branches: `main`, `develop`, `road_to_stable`

## Coverage Checking Details

### Overall Coverage (90% minimum)

The workflow checks overall code coverage by parsing the clover.xml file:

```php
// Calculates: (covered methods / total methods) * 100
// Must be >= 90%
```

### Public Function Coverage (100% required)

For each public method, the workflow verifies:

```php
// Each public function must have:
// - All statements covered
// - All branches covered
// Result: 100% coverage per public method
```

Public functions not fully covered will be listed as warnings.

## Benchmark Details

### Local vs CI

| Aspect | Local | CI |
|--------|-------|-----|
| Messages | 1,000,000 (default) | 50,000 (optimized) |
| Batch Sizes | 1, 100, 1K, 10K, 1M | 1, 100, 1K |
| Duration | ~2-5 minutes | ~30-60 seconds |

### Results Storage

Benchmark results are:
- Stored in `tests/functional/benchmark-results/`
- Available as GitHub Actions artifact
- Retained for 30 days
- Can be compared across runs

### Customizing Benchmark

To change benchmark parameters, edit `.github/workflows/tests-and-benchmarks.yml`:

```yaml
- name: Run Benchmark (Small Dataset)
  run: cd tests/functional && ./run-benchmark.sh --count 50000 --batches "1,100,1000"
```

Change `--count` and `--batches` as needed.

## Job Dependencies

```
unit-tests (PHP 8.1, 8.2)
functional-tests
benchmark
code-quality
      ↓
    summary (waits for all)
```

All jobs run in parallel except `summary` which waits for others.

## Failure Conditions

### Will Block PR:
- ❌ Unit tests fail
- ❌ Code coverage < 90%
- ❌ Functional tests fail

### Won't Block PR (Warnings Only):
- ⚠️ PHPStan findings
- ⚠️ Code style issues
- ⚠️ Benchmark issues

## Debugging Failed Workflows

### View Logs

1. Go to repository
2. Click "Actions" tab
3. Click failed workflow
4. Expand failed job
5. View step logs

### Re-run Failed Workflow

```bash
gh run rerun <run-id>
```

### Run Locally

```bash
# Unit tests
./vendor/bin/phpunit

# Functional tests
cd tests/functional && vendor/bin/behat

# Benchmark
cd tests/functional && ./run-benchmark.sh --count 10000
```

## Local Development

Before pushing, run these locally:

```bash
# Unit tests with coverage
./vendor/bin/phpunit --coverage-html=coverage/

# Functional tests
cd tests/functional && vendor/bin/behat

# Benchmark
cd tests/functional && ./run-benchmark.sh --count 50000

# Code quality
phpstan analyse src/ --level=9
php-cs-fixer fix --dry-run src/
```

## CI/CD Best Practices

### For Developers

1. **Run tests locally first**
   ```bash
   ./vendor/bin/phpunit
   ```

2. **Check coverage**
   ```bash
   ./vendor/bin/phpunit --coverage-text
   ```

3. **Run benchmark before PR**
   ```bash
   cd tests/functional && ./run-benchmark.sh --count 10000
   ```

4. **Fix code style**
   ```bash
   php-cs-fixer fix src/
   ```

### For Maintainers

1. **Review benchmark results** in Actions artifacts
2. **Monitor performance trends** across runs
3. **Adjust coverage requirements** if needed
4. **Update PHP versions** as needed

## Customization

### Changing PHP Versions

Edit `.github/workflows/tests-and-benchmarks.yml`:

```yaml
strategy:
  matrix:
    php-version: ['8.1', '8.2', '8.3']  # Add version here
```

### Changing Coverage Requirements

Edit the PHP coverage check script:

```php
if ($percentage < 90) {  // Change 90 to desired value
```

### Adding More Branches

Add to workflow triggers:

```yaml
on:
  push:
    branches: [ main, develop, road_to_stable, staging ]  # Add here
```

### Disabling Jobs

Comment out or remove job definition:

```yaml
# benchmark:    # Commented out to disable
#   name: Performance Benchmark
```

## Troubleshooting

### Issue: Coverage Check Fails

**Cause**: Code coverage below 90% or public functions not covered

**Solution**:
1. Run tests locally: `./vendor/bin/phpunit --coverage-html=coverage/`
2. Open `coverage/index.html` to see coverage gaps
3. Add tests for uncovered lines

### Issue: Functional Tests Timeout

**Cause**: NATS server not responding

**Solution**:
1. Check NATS service health in logs
2. Increase timeout in workflow if needed
3. Verify NATS connection string

### Issue: Benchmark Too Slow

**Cause**: Running full 1M message benchmark in CI

**Solution**:
1. Reduce `--count` parameter (default: 50000 in workflow)
2. Reduce batch sizes tested
3. Or run benchmark only on demand

### Issue: PHP-CS-Fixer Changes

**Cause**: Code style issues

**Solution**:
1. Run locally: `php-cs-fixer fix src/`
2. Commit changes
3. Push again

## Monitoring

### GitHub Actions Dashboard

1. Repository → Actions tab
2. See all workflow runs
3. Click specific run for details
4. Download artifacts

### Badge (Optional)

Add to README.md:

```markdown
[![Tests & Benchmarks](https://github.com/ideaconnect/symfony-nats-messenger/workflows/Tests%20&%20Benchmarks/badge.svg)](https://github.com/ideaconnect/symfony-nats-messenger/actions)
```

## Performance Targets

Based on benchmarks, typical performance on GitHub Actions:

| Metric | Target | Current |
|--------|--------|---------|
| Unit tests | < 30s | ~20-25s |
| Functional tests | < 60s | ~45-50s |
| Benchmark (50K) | < 60s | ~40-50s |
| Total pipeline | < 5min | ~3-4min |

## Support

For issues or questions:

1. Check workflow logs in GitHub Actions
2. Run tests locally
3. Review this documentation
4. Open issue if needed

## See Also

- [README.md](../../README.md) - Main documentation
- [BENCHMARK.md](../../tests/functional/BENCHMARK.md) - Benchmark details
- [phpunit.xml.dist](../../phpunit.xml.dist) - Unit test configuration
- [Behat Documentation](https://behat.org/) - Functional tests
