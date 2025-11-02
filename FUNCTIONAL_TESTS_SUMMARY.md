# NATS Messenger Transport - Functional Test Implementation Summary

## Overview

Successfully implemented and tested a comprehensive Behat-based functional test suite for the NATS Messenger Transport's setup functionality. The tests verify that the `messenger:setup-transports` command can properly create and configure NATS JetStream streams with specific parameters.

## What Was Implemented

### ✅ **Behat Test Suite**
- **Feature File**: `features/nats_setup.feature` with 3 comprehensive scenarios
- **Context Class**: `tests/Behat/NatsSetupContext.php` with full step implementations
- **Configuration**: `behat.yml` with proper test context setup

### ✅ **Test Scenarios Covered**

#### 1. **Stream Creation with Max Age Configuration**
```gherkin
Scenario: Setup NATS stream with max age configuration
  Given I have a messenger transport configured with max age of 15 minutes
  When I run the messenger setup command
  Then the NATS stream should be created successfully
  And the stream should have a max age of 15 minutes
  And the stream should be configured with the correct subject
```

#### 2. **Existing Stream Handling**
```gherkin
Scenario: Setup command handles existing streams gracefully
  Given I have a messenger transport configured with max age of 15 minutes
  And the NATS stream already exists
  When I run the messenger setup command
  Then the setup should complete successfully
  And the existing stream configuration should be preserved
```

#### 3. **Error Handling**
```gherkin
Scenario: Setup command fails gracefully when NATS is unavailable
  Given NATS server is not running
  And I have a messenger transport configured with max age of 15 minutes
  When I run the messenger setup command
  Then the setup should fail with a connection error
  And the error message should be descriptive
```

### ✅ **Docker Integration**
- **Automated NATS startup/shutdown** using Docker Compose
- **Isolated test environment** with unique container names
- **JetStream enabled** with proper authentication
- **Port conflict resolution** using alternative ports

### ✅ **Bug Fix Discovered and Resolved**
- **Issue**: NATS `setMaxAge()` requires nanoseconds, but our code was passing seconds
- **Fix**: Added conversion from seconds to nanoseconds (multiply by 1,000,000,000)
- **Location**: `src/NatsTransport.php` line 130
- **Impact**: Now correctly handles stream max age configuration

### ✅ **Test Infrastructure**
- **Temporary configuration management**: Creates test-specific config files
- **Automatic cleanup**: Removes streams, containers, and config files after tests
- **Environment isolation**: Uses `--env=test` to separate from development
- **Comprehensive error handling**: Validates error messages and exit codes

### ✅ **Documentation and Tools**
- **Comprehensive README**: `tests/functional/README.md` with usage instructions
- **Test runner script**: `run_tests.sh` with interactive menu
- **Updated documentation**: Enhanced setup guides with nanoseconds conversion notes

## Test Results

All tests are **PASSING** ✅:

```
Feature: NATS Stream Setup
  Background:
    Given NATS server is running

  Scenario: Setup NATS stream with max age configuration      ✅ PASSED
  Scenario: Setup command handles existing streams gracefully ✅ PASSED
  Scenario: Setup command fails gracefully when NATS is unavailable ✅ PASSED

3 scenarios (3 passed)
18 steps (18 passed)
```

## Technical Details

### **Configuration Management**
```yaml
# Generated test configuration
framework:
    messenger:
        transports:
            test_transport: 'nats-jetstream://admin:password@localhost:4222/test_stream/test.messages?stream_max_age=900'
```

### **Stream Verification**
- Connects to NATS JetStream API directly
- Validates stream existence and configuration
- Verifies max age in nanoseconds: `15 minutes = 900,000,000,000 nanoseconds`
- Confirms subject configuration matches expected values

### **Docker Setup**
```yaml
services:
  nats:
    image: nats:alpine
    container_name: nats_test
    ports:
      - "4222:4222"  # Client port
      - "6223:6222"  # Cluster port (modified to avoid conflicts)
      - "8223:8222"  # Monitoring port (modified to avoid conflicts)
    volumes:
      - ./nats.conf:/etc/nats/nats.conf
      - ./data:/data
```

## Usage

### **Run All Tests**
```bash
cd tests/functional
vendor/bin/behat
```

### **Run Interactive Test Menu**
```bash
cd tests/functional
./run_tests.sh
```

### **Run Specific Scenario**
```bash
vendor/bin/behat features/nats_setup.feature:9
```

## Key Learnings

1. **NATS API Requirements**: Max age must be specified in nanoseconds, not seconds
2. **Docker Integration**: Tests can reliably start/stop NATS in isolated containers
3. **Configuration Isolation**: Test environments can be completely separated using temporary config files
4. **Stream Management**: NATS streams can be programmatically created, queried, and deleted
5. **Error Validation**: Symfony commands provide consistent error handling that can be tested

## Integration with CI/CD

The functional tests are designed to work in CI/CD environments:

```bash
# CI Script example
cd tests/functional
composer install --no-dev --optimize-autoloader
vendor/bin/behat --no-interaction
```

**Requirements for CI**:
- Docker available in CI environment
- PHP 8.2+
- Composer
- Network access for Docker image pulls

## Benefits

1. **Confidence**: Real-world testing with actual NATS server
2. **Regression Prevention**: Catches configuration and API usage issues
3. **Documentation**: Tests serve as executable documentation
4. **Quality Assurance**: Validates error handling and edge cases
5. **Integration Validation**: Ensures Symfony Messenger integration works correctly

The functional test suite provides comprehensive coverage of the NATS transport setup functionality and ensures reliable behavior in production environments.