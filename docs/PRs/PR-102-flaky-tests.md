# PR #102 - The Flaky Unit Tests and Examples

- **Author:** Bartosz Pachołek
- **Branch:** `fix/flaky-tests` → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/102
- **Status:** Merged on 2026-11-06, merge commit `59a60cc`. One commit per item, with its measurements in the message.

## What the PR Did

Some unit tests and examples failed now and then with nothing wrong in the code, on `main` and on every branch. The PR fixes
them, changes nothing in `src/`, and adds a check so that a new unit test cannot come to depend on how fast its process runs.
It also carries `4ff0b0f`, which #101 holds too: Symfony Messenger 7.4.23 and 8.1.11 reworded the "No transport supports" error,
and that had turned `composer test` red on a fresh install.

## Causes and Fixes

| What failed | Why | Fix |
|---|---|---|
| `ReconnectModeTest`, with `the send waited for the held dial, it took 0.016 s` or a request that timed out | Every operation had 100 ms and a PING 20 ms, and dials were held for a set time. A machine short of CPU pauses the process for longer than that | An operation that has to succeed gets 5 s, which it never uses up. Only a send that has to run out of its budget gets a short one. A recovery the test looks at waits until the test releases it, through `holdNextDialUntilReleased()`. Getting past `ping_after_idle` moves the transport's clock instead of waiting |
| `SubscriptionLimitPullTest`, the PING test in `NatsTransportTest`, `UnansweredPullTest` | Limits on how long a call may take, and the time a PING had to be answered in, sat close to a normal run | They sit far from a normal run and under the time the wrong behaviour would take, so they still catch a call that waits |
| `examples/keepalive.php`, with `expected no redelivery after 5 keepalives, but the second worker received 0 message(s)` | It read the count right after a plain ACK, which nothing waits for, and its message named the wrong failure | It waits up to 10 s for the count to reach 0, and a lost ACK still fails it. Each check has a message of its own |
| `handler-failing-during-failover` and `handler-succeeding-during-failover` on NATS 2.10, with `no suitable peers for placement, peer offline` | `waitForClusterServer()` waited until a rejoined server answered JetStream requests, which does not show that it can place a stream. The meta leader's `/jsz` shows every peer online during the refusal, so waiting on that does not help either | It waits until that server can create and delete a stream with three replicas |

## Reproducing a Timing Failure

Stop the process at random moments with SIGSTOP and SIGCONT rather than loading the machine. A stopped process uses no CPU, so
this slows nothing else, and it fails far more often. At pauses of 120 ms the old tests failed 8 of 8 runs, where 12 busy loops on
one core failed 4 of 52 runs and real load of 12 to 17 failed 3 of 270. `composer test:unit:paused` does this for the unit tests
(`scripts/run-tests-with-pauses.php`, with `TEST_PAUSE_MS` for the length of a stop).

## Tests

- `composer test:unit:paused` runs in CI on the PHP 8.5 job, so a new unit test with a short time limit fails there instead of
  failing rarely on someone's busy machine. It took 36 s on the first CI run and takes about 15 s on a fast machine.
- `RunTestsWithPausesTest` checks that a run is really paused, that its arguments reach PHPUnit and that PHPUnit's exit code comes
  through. A script that stopped pausing would otherwise keep passing and guard nothing.
- The acknowledgement test in `ReconnectModeTest` still catches what it was written for: with a publish patched to wait for the
  recovery, as client 2.27.0 did, it fails on both rows.
- `docs/TESTS.md`, "Unit Tests With Pauses", has the rules a unit test follows, and `CLAUDE.md` and `AGENTS.md` repeat them.

## Validation

Runs that failed:

| Check | Before | After |
|---|---|---|
| Unit suite stopped at random moments for 60 ms | 2 of 8 | 0 of 8 |
| Same, for 120 ms | 8 of 8 | 0 of 8 |
| Same, for 250 ms | 8 of 8 | 0 of 8 |
| Same, for 500 ms | not run | 0 of 4 |
| Same, for 1 s | not run | 0 of 3 |
| `ReconnectModeTest` and `SubscriptionLimitPullTest` on one CPU core shared with 12 busy loops | 4 of 52 | 0 of 52 |
| All examples on NATS 2.10.29 | 4 of 25 | 0 of 50 |
| All examples on NATS 2.15.2 | not run | 0 of 20 |
| All examples stopped for 120 ms at a time, on NATS 2.10.29 | not run | 0 of 33 |
| Restart a cluster server, then create a stream with three replicas, on NATS 2.10.29 | 6 of 45 | 0 of 45 |

At the tip of the branch, before #101:

- PHPStan is clean and PHPUnit passes at every commit: 728 tests on PHP 8.5.11, and on PHP 8.2.35 with PHPUnit 11.5.57 and client
  2.28.0, where the paused run passed 6 of 6.
- Infection: the canary's 63 mutants all escaped, 1043 of 1058 mutants were killed with the 15 survivors `docs/TESTS.md` lists, and
  coverage was 99.79% of statements.
- CI's first run passed all three jobs: 728 unit tests on each PHP job, then 728 again stopped 111 times on PHP 8.5, 61 of 61 Behat
  scenarios on each PHP job, 57 of 57 on the oldest NATS job, and 11 of 11 examples on each job.

One green CI run cannot show that a flake is gone, so the repeated runs above are the evidence for that.

## Landing Next to #101

`main` went into #101's branch with conflicts only in `README.md` (the test count, and the message-count section) and
`docs/CHANGELOG.md`. The merged tree's 1038 unit tests pass, also stopped at random moments, so #101's new tests needed no change.

## Left Open

A pull in the last moments of a graceful stop on NATS 2.15 can get 503, which the transport reads as a missing consumer, so a restart
scenario on a runner short of CPU can still fail with it. That is the transport's behaviour rather than a test's, so this PR does
not change it: see the README's Automatic Reconnect section and `docs/PROJECT_MEMORY.md`, "Behavior to preserve".
