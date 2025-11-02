#!/bin/bash

# Unit Test Execution Script
# This script helps run the unit tests with various options

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENDOR_BIN="$SCRIPT_DIR/vendor/bin/phpunit"

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if PHPUnit is installed
if [ ! -f "$VENDOR_BIN" ]; then
    echo -e "${YELLOW}PHPUnit not found. Installing...${NC}"
    composer install --dev
fi

# Function to display usage
usage() {
    cat << EOF
Usage: ./run-tests.sh [OPTION]

Options:
    all              Run all unit tests (default)
    factory          Run only NatsTransportFactory tests
    transport        Run only NatsTransport tests
    coverage         Run all tests with HTML coverage report
    coverage-text    Run all tests with text coverage report
    watch            Run tests in watch mode (requires inotify-tools)
    verbose          Run all tests with verbose output
    debug            Run all tests with debug output
    filter PATTERN   Run tests matching PATTERN
    help             Display this help message

Examples:
    ./run-tests.sh                      # Run all tests
    ./run-tests.sh coverage             # Generate HTML coverage
    ./run-tests.sh filter DSN           # Run only DSN-related tests
    ./run-tests.sh factory              # Run factory tests only

EOF
}

# Function to run all tests
run_all_tests() {
    echo -e "${BLUE}Running all unit tests...${NC}"
    "$VENDOR_BIN"
}

# Function to run tests with coverage
run_coverage() {
    echo -e "${BLUE}Generating HTML coverage report...${NC}"
    "$VENDOR_BIN" --coverage-html coverage/
    echo -e "${GREEN}✓ Coverage report generated in coverage/index.html${NC}"

    # Try to open in browser
    if command -v xdg-open &> /dev/null; then
        xdg-open coverage/index.html
    elif command -v open &> /dev/null; then
        open coverage/index.html
    fi
}

# Function to run tests with text coverage
run_coverage_text() {
    echo -e "${BLUE}Running tests with text coverage report...${NC}"
    "$VENDOR_BIN" --coverage-text
}

# Function to run factory tests
run_factory_tests() {
    echo -e "${BLUE}Running NatsTransportFactory tests...${NC}"
    "$VENDOR_BIN" tests/unit/NatsTransportFactoryTest.php
}

# Function to run transport tests
run_transport_tests() {
    echo -e "${BLUE}Running NatsTransport tests...${NC}"
    "$VENDOR_BIN" tests/unit/NatsTransportTest.php
}

# Function to run tests with verbose output
run_verbose() {
    echo -e "${BLUE}Running all tests with verbose output...${NC}"
    "$VENDOR_BIN" -v
}

# Function to run tests with debug output
run_debug() {
    echo -e "${BLUE}Running all tests with debug output...${NC}"
    "$VENDOR_BIN" -vv
}

# Function to run filtered tests
run_filter() {
    local pattern=$1
    echo -e "${BLUE}Running tests matching pattern: $pattern${NC}"
    "$VENDOR_BIN" --filter "$pattern"
}

# Main script logic
case "${1:-all}" in
    all)
        run_all_tests
        ;;
    factory)
        run_factory_tests
        ;;
    transport)
        run_transport_tests
        ;;
    coverage)
        run_coverage
        ;;
    coverage-text)
        run_coverage_text
        ;;
    verbose)
        run_verbose
        ;;
    debug)
        run_debug
        ;;
    filter)
        if [ -z "$2" ]; then
            echo "Error: filter requires a pattern argument"
            usage
            exit 1
        fi
        run_filter "$2"
        ;;
    help|--help|-h)
        usage
        ;;
    *)
        echo "Error: unknown option '$1'"
        usage
        exit 1
        ;;
esac

echo -e "${GREEN}✓ Tests completed successfully${NC}"
