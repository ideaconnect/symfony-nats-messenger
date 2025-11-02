# NATS Messenger Performance Benchmark

This document explains the comprehensive performance benchmark suite for the NATS Messenger integration.

## Overview

The benchmark suite is designed to measure the performance of the NATS JetStream messenger transport under realistic load conditions. It tests the system with **1,000,000 messages** across multiple batching configurations to understand throughput and memory usage patterns.

## Key Features

- **Lightweight Message Type**: `BenchmarkMessage` performs zero I/O operations for pure transport overhead measurement
- **Zero-work Handler**: `BenchmarkMessageHandler` counts messages without file I/O or database operations
- **Multiple Batch Configurations**: Tests with batch sizes of 1, 100, 1,000, 10,000, and 1,000,000
- **Comprehensive Metrics**: Collects total memory usage, peak memory, execution time, and throughput
- **Detailed Reporting**: Generates comparison tables and human-readable output

## Architecture

### New Components

#### `BenchmarkMessage.php`
Lightweight message class optimized for benchmarking:
- No serializable properties beyond `messageId` and `timestamp`
- Minimal memory footprint
- Designed for transport overhead measurement

```php
class BenchmarkMessage {
    public int $messageId = 0;
    public int $timestamp = 0;
}
```

#### `BenchmarkMessageHandler.php`
Minimal message handler:
- Counts processed messages using static counter
- No file I/O or database operations
- Measures pure message processing overhead

```php
#[AsMessageHandler]
class BenchmarkMessageHandler {
    private static int $processedCount = 0;

    public function __invoke(BenchmarkMessage $message): void {
        self::$processedCount++;
    }
}
```

#### `BenchmarkMetrics.php`
Comprehensive metrics collection:
- Tracks start/end time and memory usage
- Calculates throughput (messages/second)
- Provides human-readable formatting for all metrics

Key methods:
- `start()` / `end()`: Bracket the operation
- `getTotalTime()`: Returns elapsed seconds
- `getMemoryUsed()`: Returns memory delta
- `getPeakMemory()`: Returns peak memory usage
- `getThroughput()`: Returns messages per second

Static formatting methods:
- `formatMemory(int)`: Converts bytes to B/KB/MB/GB
- `formatTime(float)`: Converts seconds to μs/ms/s
- `formatThroughput(float)`: Formats messages/second with M/K prefixes

#### `BenchmarkMessengerCommand.php`
Main benchmark command with the following features:

**Options:**
- `--count` / `-c`: Number of messages (default: 1,000,000)
- `--batches` / `-b`: Comma-separated batch sizes (default: 1,100,1000,10000,1000000)
- `--transport` / `-t`: Transport name (default: nats_jetstream)
- `--skip-send` / `-s`: Skip sending phase
- `--skip-consume`: Skip consumption phase

**Output:**
- Progress bars for both send and consume phases
- Real-time memory and throughput display
- Summary table comparing all batch configurations

## Usage

### Basic Benchmark (Recommended)

Run the full benchmark with default settings:

```bash
cd tests/functional
./run-benchmark.sh
```

Or directly via the command:

```bash
php bin/console app:benchmark-messenger
```

### Custom Message Count

Benchmark with fewer messages for faster iteration:

```bash
./run-benchmark.sh --count 100000
php bin/console app:benchmark-messenger --count 100000
```

### Custom Batch Sizes

Test only specific batch sizes:

```bash
./run-benchmark.sh --batches "1,50,500,5000"
php bin/console app:benchmark-messenger --batches "1,50,500,5000"
```

### Send Phase Only

Only send messages without consuming:

```bash
./run-benchmark.sh --skip-consume
php bin/console app:benchmark-messenger --skip-consume
```

### Consume Phase Only

Only test consumption (for testing consumption performance separately):

```bash
./run-benchmark.sh --skip-send
php bin/console app:benchmark-messenger --skip-send
```

### Combined Example

```bash
./run-benchmark.sh --count 500000 --batches "1,100,1000,10000" --skip-send
```

## Output Interpretation

### Progress Bars

The benchmark displays detailed progress bars for each phase:

```
Sent: 1,000,000/1,000,000 [████████████████████████████] 100% 45.2 MB 12.34 s 81.0K msg/s
```

Shows: Current count, progress bar, percentage, current memory, elapsed time, rate

### Metrics Table

Example metrics display for a single phase:

```
Phase:        SEND
Messages:     1,000,000
Batch Size:   1
Total Time:   12.34 s
Memory Used:  45.20 MB
Peak Memory:  128.50 MB
Throughput:   81,038.52 msg/s
```

### Summary Table

Comparison of all batch configurations:

```
┌───────────────────┬──────────────┬──────────────┬────────────┬──────────────┬────────────────┬──────────────────┐
│ Phase             │ Batch Size   │ Messages     │ Total Time │ Memory Used  │ Peak Memory    │ Throughput       │
├───────────────────┼──────────────┼──────────────┼────────────┼──────────────┼────────────────┼──────────────────┤
│ SEND              │ 1            │ 1,000,000    │ 12.34 s    │ 45.20 MB     │ 128.50 MB      │ 81,038.52 msg/s  │
│ CONSUME (batch=1) │ 1            │ 1,000,000    │ 8.56 s     │ 32.10 MB     │ 95.20 MB       │ 116,822.43 msg/s │
│ CONSUME (batch=100) │ 100        │ 1,000,000    │ 7.21 s     │ 28.50 MB     │ 87.30 MB       │ 138,697.11 msg/s │
│ CONSUME (batch=1000) │ 1,000     │ 1,000,000    │ 6.45 s     │ 26.80 MB     │ 82.10 MB       │ 155,038.76 msg/s │
└───────────────────┴──────────────┴──────────────┴────────────┴──────────────┴────────────────┴──────────────────┘
```

## Key Metrics Explained

### Total Time
The elapsed wall-clock time from start to completion. Measured in seconds (s), milliseconds (ms), or microseconds (μs).

**What it measures:** Overall throughput capacity
**Optimization target:** Lower is better

### Memory Used
The difference between starting memory and ending memory. Includes all allocations during the benchmark.

**What it measures:** Memory efficiency during operation
**Optimization target:** Lower is better
**Note:** May be negative on systems with garbage collection between measurements

### Peak Memory
The highest memory usage during the benchmark. Useful for understanding maximum resource requirements.

**What it measures:** Maximum memory footprint
**Optimization target:** Lower is better
**Use case:** Determining server requirements

### Throughput
Messages processed per second. Calculated as: `messageCount / totalTime`

**What it measures:** Processing capacity
**Formula:** messages ÷ time = messages/second
**Display:**
- `M msg/s` for millions per second
- `K msg/s` for thousands per second
- `msg/s` for regular messages per second

## Expected Results & Patterns

### Typical Findings

1. **Larger Batches → Better Throughput**
   - Batch size 1: ~80K-100K msg/s
   - Batch size 100: ~120K-150K msg/s
   - Batch size 1000: ~150K-200K msg/s
   - Batch size 1M: ~200K-300K msg/s

2. **Memory Usage vs Batch Size**
   - Larger batches typically use slightly more memory
   - Memory usage mostly depends on message size, not batch size
   - Peak memory can spike with large batches

3. **Send vs Consume**
   - Consume is typically faster than send (no serialization overhead)
   - Send phase has higher variance due to I/O
   - Consume phase is more consistent

### Performance Bottlenecks

**If you see low throughput:**
- Check NATS server health and network connectivity
- Monitor NATS server CPU/memory during benchmark
- Try increasing batch size
- Check for GC pauses in PHP logs

**If you see high memory usage:**
- Reduce message batch size
- Check for memory leaks in handlers
- Monitor long-running consume phase

**If you see inconsistent results:**
- Run multiple times to warm up PHP opcache
- Close other applications
- Check system load
- Consider dedicated benchmark hardware

## Running on Production-like Systems

### Docker Compose Setup

Use the included docker-compose to run NATS server:

```bash
cd tests/functional/nats
docker-compose up -d
cd ..
```

Then run benchmark:

```bash
./run-benchmark.sh --count 1000000
```

### System Requirements

For full 1M message benchmark:
- **Memory:** 512 MB minimum (1 GB recommended)
- **CPU:** Dual-core minimum (quad-core recommended)
- **Disk:** 100 MB free (for NATS persistence)
- **Network:** Local or fast network to NATS

### Tuning for Maximum Throughput

1. **Disable XDebug and Profilers**
   ```bash
   php -d zend_extension= bin/console app:benchmark-messenger
   ```

2. **Increase PHP Memory Limit**
   ```bash
   php -d memory_limit=2G bin/console app:benchmark-messenger
   ```

3. **Use Large Batch Sizes**
   ```bash
   ./run-benchmark.sh --batches "10000,100000,1000000"
   ```

4. **Reduce GC Collection Frequency**
   ```bash
   php -d gc.collect_cycles=0 bin/console app:benchmark-messenger
   ```

## Troubleshooting

### Connection Refused

**Error:** `Connection refused to nats_jetstream://localhost:4222`

**Solutions:**
- Ensure NATS server is running: `docker ps | grep nats`
- Check NATS configuration: `cat nats/nats.conf`
- Try explicit port: `app:benchmark-messenger --transport=nats_jetstream`

### Out of Memory

**Error:** `PHP Fatal error: Allowed memory size exhausted`

**Solutions:**
- Reduce message count: `--count 100000`
- Use smaller batches: `--batches "1,10,100"`
- Increase PHP limit: `php -d memory_limit=4G`

### Slow Throughput

**Typical throughput:**
- Send phase: 50K-100K msg/s
- Consume phase: 100K-300K msg/s

**If you get much lower:**
- Check network latency to NATS
- Monitor CPU during benchmark
- Check for disk I/O issues
- Verify NATS configuration for performance

### Inconsistent Results

**Normal variation:** 5-10% between runs is expected

**If variation is > 20%:**
- Ensure NATS server stability
- Close background applications
- Disable sleep/power-saving modes
- Consider system tuning

## Advanced Usage

### Comparative Benchmarking

Run benchmark, tweak something, run again:

```bash
# Baseline
./run-benchmark.sh --count 500000 --batches "100,1000" > baseline.txt

# After configuration change
./run-benchmark.sh --count 500000 --batches "100,1000" > modified.txt

# Compare
diff baseline.txt modified.txt
```

### Profiling with Blackfire

If you have Blackfire installed:

```bash
blackfire run php bin/console app:benchmark-messenger --count 100000
```

### Memory Profiling

Use valgrind for detailed memory analysis:

```bash
valgrind --leak-check=full php bin/console app:benchmark-messenger --count 10000
```

## Files Included

```
tests/functional/
├── run-benchmark.sh                           # Executable benchmark script
├── src/
│   ├── Async/
│   │   ├── BenchmarkMessage.php               # Lightweight benchmark message
│   │   └── BenchmarkMessageHandler.php        # Zero-work message handler
│   ├── Benchmark/
│   │   └── BenchmarkMetrics.php               # Metrics collection & formatting
│   └── Command/
│       └── BenchmarkMessengerCommand.php      # Main benchmark command
└── BENCHMARK.md                               # This file
```

## Contributing Improvements

When running benchmarks, please:

1. Document your system specs (CPU, RAM, OS)
2. Run with default settings first: `./run-benchmark.sh`
3. Include output when reporting issues
4. Note any deviations from expected performance

Example report:
```
System: Ubuntu 22.04, Intel i7-10700K, 64GB RAM
Baseline: ./run-benchmark.sh
Results: Attached as benchmark-result.txt
Note: Server also running MySQL - might affect results
```

## See Also

- [README.md](../README.md) - Main documentation
- [tests/functional/README.md](README.md) - Functional test setup
- [NATS JetStream Documentation](https://docs.nats.io/nats-concepts/jetstream)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)
