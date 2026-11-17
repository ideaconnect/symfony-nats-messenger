# PR #114 - An Empty Pull Leaves No Reference Cycle Behind

- **Author:** [matkomat](https://github.com/ideaconnect/symfony-nats-messenger/pull/114) (Matko Anđelinić)
- **Branch:** `empty-pull-reference-cycle` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/114, fixing
  [#115](https://github.com/ideaconnect/symfony-nats-messenger/issues/115)
- **Status:** Merged on 2026-11-17 with a merge commit, `6116173`, and released in 5.8.1. The contributor's seven commits stay
  unchanged: the fix and its docs, then one for each of the five required changes. This record and the rule in `CLAUDE.md`,
  `AGENTS.md` and `docs/PROJECT_MEMORY.md` were added on the same branch before the merge.
- **Reviewed at:** commit `14e0fd9` (review on 2026-11-14, decision on 2026-11-16), on `main` at `3e1d140`. Verified again at
  `88ab821`, after the five required changes: 4 files, +190 / -19.

## What the PR Proposes

An idle `messenger:consume` worker on this transport grew its heap by about 13 KB on every pull that found no messages, about
0.8 MB a minute at the default of one pull a second, and kept that memory.

A pull that finds no messages ends with a `JetStreamException` (408) raised in the event loop. With `zend.exception_ignore_args`
off, the default without a php.ini (the official `php:8.4-cli` and `php:8.5-cli` images load none), the trace of that exception
records the arguments of every frame waiting on it. One of them was `awaitOnConnection(Future $call)`, so the trace held the
pull's `Future`, which held the exception. Only the cycle collector frees such a cycle: in a worker, after the next handled
message (Symfony's `ResetMemoryUsageListener`, 7.3 and later), or when its root buffer fills up, tens of minutes into an idle
period. A failed publish, ACK, NAK, TERM, message count or setup call left the same kind of cycle.

`awaitOnConnection()` now takes a closure that starts the call (`\Closure(): Future<T>`), so no frame records the `Future` as
an argument. The closure runs before the `try`, as the argument expression did, so a call that throws before it returns a
`Future` (a refused dial, a name the client refuses) leaves the connection check as it was.

## Decision

The closure stays. The PR body also offered a shorter form, keeping the signature and clearing the parameter inside
(`$future = $call; $call = null;`); it measures 0 cycles too, but a missing `$call = null;` does not look wrong in a review,
while a closure that returns a Future it did not start does. The type alone does not hold the fix: a closure that captures a
Future made before the call (`fn (): Future => $publish`) passes PHPStan and brings the cycle back at that call site. A test of
each operation holds it, so the review asked for five changes, one commit each:

| Required change | Commit |
|---|---|
| `testAFailedCallLeavesNoReferenceCycle` with `operationsWhoseCallFails`, one row per operation that awaits on the connection | `fcfc534` |
| `testARefusedDialDoesNotMakeTheOperationAfterTheNextDialCheckTheConnection`, pinning the call before the `try` | `c790d37` |
| The `assertNotFalse()` guard after `ini_set()` in both cycle tests, before the `try` | `cddb0e4` |
| The `awaitOnConnection()` doc-comment: the setting, what brings the cycle back, why the call runs before the `try` | `c86c6d4` |
| `docs/TESTS.md` (a #115 row, the order test in the connection-check row) and the CHANGELOG entry | `88ab821` |

## What Was Measured

An idle Symfony `Worker` with default options against a real NATS 2.15.2, on PHP 8.5.9 with symfony/messenger 8.1.11 and client
2.29.0:

| | `main` | this PR |
|---|---|---|
| 30 empty pulls, `exception_ignore_args=0` (at `14e0fd9`) | 13,169 B and 37 cyclic objects per pull | 72.8 B and 0 |
| The same with `exception_ignore_args=1` | 72.8 B and 0 | |
| 20 empty pulls, `exception_ignore_args=0`, collector off during the run (at `88ab821`) | 13,208 B and 37.8 per pull | 144 B and 0.8 |
| The same with `exception_ignore_args=1` | 144 B and 0.8 | 144 B and 0.8 |

The 0.8 is 16 cyclic objects made once per run, not per pull: the PR leaves exactly 16 after 5, 20 and 40 pulls. Its heap grew
by 160 B in total after 5 pulls, 2,880 B after 20 and 3,520 B after 40 (at `14e0fd9`, the fixed rows were one step of 2,184 B
spread over the run), against 13 KB per pull on `main`. The 30 pulls took 30.1 s and the 20 took 21.0 s, so at the defaults an
idle worker does pull once a second.

Failure paths from #115, cyclic objects per failed call with `exception_ignore_args=0`:

| Operation | `main` | this PR |
|---|---|---|
| Empty pull (408) | 39 | 0 |
| Pull of a missing consumer (503) | 39 | 0 |
| Publish to a missing stream | 79 | 0 |
| `ack_sync` without a responder | 39 | 0 |
| `getMessageCount()`, both lookups failing | 84 | 0 |
| `setup()` refused (subjects overlap) | 87 | 0 |

With `exception_ignore_args=1` every cell is 0.

## How the Tests Hold the Fix

The nine new cases (the empty pull, the seven rows and the order test) were run against altered copies of
`src/NatsTransport.php`:

| Variant | Result |
|---|---|
| The PR | all 9 pass |
| `main`'s `src/NatsTransport.php` | 8 fail (the empty pull and all 7 rows); the order test passes |
| `$future = $call();` moved inside the `try` | only the order test fails |
| `send()` awaits a Future made before the call | only the `send` row fails |
| `ack` with `ack_sync` awaits a Future made before the call | only that row fails |
| `setup()`'s `addStream` awaits a Future made before the call | only the `setup` row fails |
| A helper that takes the Future as a parameter | 8 fail |
| The same helper with its parameter cleared (`$call = null;`) | all 9 pass: the shorter form avoids the cycle too |

Both cycle tests make the exception in an `EventLoop::defer()` callback, so it is made while the operation waits. An exception
passed to `Future::error()` is made before the wait and records no frame that waits on the Future: with it, all seven rows pass
on `main` too. Both are skipped under Xdebug's develop mode, which keeps thrown exceptions of its own.

## Validation

At `88ab821`, PHP 8.5.9, PHPUnit 13.4.7, PHPStan 2.3.6:

- `composer test`: PHPStan at level max clean; 1068 tests, 3122 assertions. Also in 5 random orders and under
  `composer test:unit:paused`.
- Coverage 99.80% (1023/1025); `src/NatsTransport.php` 503/503, every changed statement covered.
- Infection on the changed lines at `14e0fd9`: 24 mutants, 24 killed.
- Against the repo's `tests/nats` stack (NATS 2.15.2): `EXAMPLES_STRICT=1 composer examples` 14 passed; the Behat features that
  drive publish, ACK, `ack_sync`, NAK, TERM, setup, pulls and the connection check, 42 scenarios and 346 steps, all passed.
- Not run locally: PHP 8.2 (CI's lowest leg), the Behat features for auth, TLS, stream limits and placement, and the full
  mutation run. CI runs them.

At `5e02bad`, the head that was merged (only docs changed after `88ab821`), PHP 8.5.11, PHPUnit 13.4.6:

- `composer test`, 5 random orders, `composer test:unit:paused` (58 pauses of 250 ms) and coverage (99.80%) all passed.
- The PR, `main`'s `src/NatsTransport.php`, the call moved inside the `try`, `send()` awaiting a Future made before the call
  and the helper that takes the Future gave the results in the table above.
- An idle `Worker` against NATS 2.15.2, with the heap recorded at 10, 30, 60 and 90 empty pulls: +2,184 B, +2,560 B, +2,560 B
  and +2,560 B, the same with `exception_ignore_args` on and off, and 0 cyclic objects after 90 pulls (`main`: 37 per pull and
  13,169 B). The heap stops growing after the first pulls.
- CI on the same head, run 43913469827: 1068 tests on PHP 8.2, with the lowest client release the transport allows (2.29.0),
  and on PHP 8.5; coverage 99.80%; Infection killed 1179 of 1197 mutants, the 18 survivors being the ones `docs/TESTS.md`
  lists; Behat 94 of 94 on each PHP job and 90 of 90 on NATS 2.10.29; 14 of 14 examples in each job.

## Left Open

The PING's `$ping->await(new TimeoutCancellation(...))` leaves a cycle when its timeout fires: 99 cyclic objects with
`exception_ignore_args=0`, none for an answered PING or with `=1`. amphp makes the `TimeoutException` in the event loop, so its
trace records the cancellation as an argument of `Future::await()`; the cancellation holds a `CancelledException` whose previous
exception is the `TimeoutException`. It forms once per connection that stopped answering, and that connection is then replaced,
so it is not a leak in an idle worker. No change was made for it.
