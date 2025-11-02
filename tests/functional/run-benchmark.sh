#!/bin/bash

# Benchmark script for NATS Messenger performance testing
#
# This script runs comprehensive benchmarks with different batching settings
# and generates a detailed report of memory usage and throughput.
#
# Usage:
#   ./run-benchmark.sh                          # Run with defaults (1M messages)
#   ./run-benchmark.sh --count 100000           # Custom message count
#   ./run-benchmark.sh --batches "1,10,100"     # Custom batch sizes
#   ./run-benchmark.sh --skip-send               # Skip send phase
#   ./run-benchmark.sh --skip-consume            # Skip consume phase

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Default values
MESSAGE_COUNT=1000000
BATCH_SIZES="1,100,1000,10000,1000000"
SKIP_SEND=""
SKIP_CONSUME=""
TRANSPORT="nats_jetstream"

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --count)
            MESSAGE_COUNT="$2"
            shift 2
            ;;
        --batches)
            BATCH_SIZES="$2"
            shift 2
            ;;
        --skip-send)
            SKIP_SEND="--skip-send"
            shift
            ;;
        --skip-consume)
            SKIP_CONSUME="--skip-consume"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --count COUNT           Number of messages to send (default: 1000000)"
            echo "  --batches SIZES         Comma-separated batch sizes (default: 1,100,1000,10000,1000000)"
            echo "  --skip-send             Skip the send phase"
            echo "  --skip-consume          Skip the consume phase"
            echo "  --help                  Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                                      # Full benchmark with defaults"
            echo "  $0 --count 500000                       # Custom message count"
            echo "  $0 --batches \"1,50,500\"               # Custom batch sizes"
            echo "  $0 --skip-send                          # Only test consumption"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Print header
echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║${NC}                                                              ${BLUE}║${NC}"
echo -e "${BLUE}║${NC}        📊 NATS Messenger Performance Benchmark Suite        ${BLUE}║${NC}"
echo -e "${BLUE}║${NC}                                                              ${BLUE}║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Print configuration
echo -e "${CYAN}Configuration:${NC}"
echo -e "  ${YELLOW}Message Count:${NC}   $(echo $MESSAGE_COUNT | sed ':a;s/\B[0-9]\{3\}\>/,&/g;ta')"
echo -e "  ${YELLOW}Batch Sizes:${NC}     $BATCH_SIZES"
echo -e "  ${YELLOW}Transport:${NC}       $TRANSPORT"
echo ""

# Verify NATS server is running
echo -e "${CYAN}Pre-flight Checks:${NC}"
if command -v nats &> /dev/null; then
    echo -e "  ${GREEN}✓${NC} nats CLI found"

    if nats server ls &> /dev/null 2>&1 || docker ps --filter "ancestor=nats" --quiet &> /dev/null; then
        echo -e "  ${GREEN}✓${NC} NATS server appears to be running"
    else
        echo -e "  ${YELLOW}⚠${NC}  NATS server might not be running (continuing anyway)"
    fi
else
    echo -e "  ${YELLOW}⚠${NC}  nats CLI not found (continuing anyway)"
fi
echo ""

# Run the benchmark command
echo -e "${CYAN}Running Benchmark...${NC}"
echo ""

php bin/console app:benchmark-messenger \
    --count="$MESSAGE_COUNT" \
    --batches="$BATCH_SIZES" \
    $SKIP_SEND \
    $SKIP_CONSUME

echo ""
echo -e "${GREEN}✓ Benchmark completed successfully!${NC}"
