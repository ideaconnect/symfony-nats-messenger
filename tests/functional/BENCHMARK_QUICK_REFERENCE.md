# Benchmark Quick Reference

## One-Command Benchmark

```bash
cd tests/functional && ./run-benchmark.sh
```

## What Gets Tested

| Phase | Details |
|-------|---------|
| **Send Phase** | 1,000,000 messages dispatched via MessageBus |
| **Consume Phase** | Messages consumed with different batch sizes |
| **Batch Sizes** | 1, 100, 1,000, 10,000, 1,000,000 |

## Metrics Collected

| Metric | Meaning | Target |
|--------|---------|--------|
| **Total Time** | Wall-clock execution time | Lower is better |
| **Memory Used** | Memory delta from start to end | Lower is better |
| **Peak Memory** | Highest memory during execution | Lower is better |
| **Throughput** | Messages processed per second | Higher is better |

## Example Output

```
┌───────────────────┬──────────────┬──────────────┬────────────┬──────────────┬────────────────┬──────────────────┐
│ Phase             │ Batch Size   │ Messages     │ Total Time │ Memory Used  │ Peak Memory    │ Throughput       │
├───────────────────┼──────────────┼──────────────┼────────────┼──────────────┼────────────────┼──────────────────┤
│ SEND              │ 1            │ 1,000,000    │ 12.34 s    │ 45.20 MB     │ 128.50 MB      │ 81,038.52 msg/s  │
│ CONSUME (batch=1) │ 1            │ 1,000,000    │ 8.56 s     │ 32.10 MB     │ 95.20 MB       │ 116,822.43 msg/s │
│ CONSUME (batch=100) │ 100        │ 1,000,000    │ 7.21 s     │ 28.50 MB     │ 87.30 MB       │ 138,697.11 msg/s │
└───────────────────┴──────────────┴──────────────┴────────────┴──────────────┴────────────────┴──────────────────┘
```

## Common Commands

### Quick benchmark with less data
```bash
./run-benchmark.sh --count 100000
```

### Test specific batch sizes
```bash
./run-benchmark.sh --batches "1,50,500,5000"
```

### Only test sending
```bash
./run-benchmark.sh --skip-consume
```

### Only test consuming
```bash
./run-benchmark.sh --skip-send
```

### Full help
```bash
./run-benchmark.sh --help
```

## Direct PHP Command

```bash
php bin/console app:benchmark-messenger [options]

Options:
  --count=N           Number of messages (default: 1000000)
  --batches=LIST      Comma-separated batch sizes (default: 1,100,1000,10000,1000000)
  --skip-send         Skip send phase
  --skip-consume      Skip consume phase
```

## Expected Performance

| Batch Size | Typical Throughput |
|------------|-------------------|
| 1 | 80K-100K msg/s |
| 100 | 120K-150K msg/s |
| 1,000 | 150K-200K msg/s |
| 10,000 | 180K-250K msg/s |
| 1,000,000 | 200K-300K msg/s |

*Note: Results vary based on system hardware and NATS server performance*

## Prerequisites

1. **NATS Server Running**
   ```bash
   cd tests/functional/nats
   docker-compose up -d
   cd ..
   ```

2. **Dependencies Installed**
   ```bash
   composer install
   ```

3. **Benchmark Command Available**
   The command is auto-registered via Symfony autoconfiguration

## Performance Tips

- Larger batches = higher throughput but more memory
- Batch size of 100-1000 offers good balance
- Results may vary ±5% - run multiple times
- Close other applications for consistent results
- Use `--skip-consume` or `--skip-send` to isolate phases

## Interpretation

**Good Results:**
- Throughput: 100K+ msg/s
- Memory: < 100 MB used
- Linear scaling with batch size

**Needs Investigation:**
- Throughput: < 50K msg/s
- Memory spike: > 500 MB
- Large variance between runs (> 20%)

## See Full Documentation

```bash
cat BENCHMARK.md
```
