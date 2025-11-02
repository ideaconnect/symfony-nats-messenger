#!/bin/bash

# NATS Messenger Transport - Functional Test Runner
# This script helps run the functional tests for NATS transport setup functionality

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}NATS Messenger Transport - Functional Tests${NC}"
echo "============================================="

# Check if we're in the right directory
if [ ! -f "behat.yml" ]; then
    echo -e "${RED}Error: Please run this script from tests/functional directory${NC}"
    exit 1
fi

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed or not in PATH${NC}"
    echo "Please install Docker to run these tests"
    exit 1
fi

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    echo -e "${YELLOW}Installing Composer dependencies...${NC}"
    composer install
fi

echo ""
echo "Available test options:"
echo "1. Run all tests"
echo "2. Run specific scenario (setup with max age)"
echo "3. Run specific scenario (existing stream handling)"
echo "4. Run specific scenario (NATS unavailable)"
echo "5. Dry run (syntax check only)"
echo "6. Show test definitions"
echo ""

# Check if we're running in CI mode
if [ "$CI" = "true" ]; then
    echo -e "${YELLOW}CI mode detected - automatically running all tests${NC}"
    choice=1
else
    read -p "Choose option (1-6): " choice
fi

case $choice in
    1)
        echo -e "${GREEN}Running all functional tests...${NC}"
        vendor/bin/behat
        ;;
    2)
        echo -e "${GREEN}Running: Setup NATS stream with max age configuration${NC}"
        vendor/bin/behat features/nats_setup.feature:9
        ;;
    3)
        echo -e "${GREEN}Running: Setup command handles existing streams gracefully${NC}"
        vendor/bin/behat features/nats_setup.feature:16
        ;;
    4)
        echo -e "${GREEN}Running: Setup command fails gracefully when NATS is unavailable${NC}"
        vendor/bin/behat features/nats_setup.feature:23
        ;;
    5)
        echo -e "${GREEN}Running dry run (syntax check)...${NC}"
        vendor/bin/behat --dry-run
        ;;
    6)
        echo -e "${GREEN}Showing test step definitions...${NC}"
        vendor/bin/behat --definitions
        ;;
    *)
        echo -e "${RED}Invalid option. Please choose 1-6.${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}Test execution completed!${NC}"

# Show cleanup reminder
echo ""
echo -e "${YELLOW}Note: Tests automatically clean up Docker containers and temporary files.${NC}"
echo "If tests were interrupted, you can manually clean up with:"
echo "  cd nats && docker compose down"
echo "  rm -f config/packages/test_messenger.yaml"