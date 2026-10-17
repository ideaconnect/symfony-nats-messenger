# PR #45 (reconnect half) - `reconnect` and `max_reconnect_attempts`

- **Author:** Petar Fedorovsky (sofascore)
- **Branch:** `feat/81-reconnect-mode` → `ideaconnect:main`, superseding the reconnect half of
  https://github.com/ideaconnect/symfony-nats-messenger/pull/45; the placement half shipped in 5.6.0 (#82)
- **Issue:** https://github.com/ideaconnect/symfony-nats-messenger/issues/81 (the design, four review comments, the
  decisions this branch follows)
- **Status:** the reconnect half of #45 adapted into the mode #81 describes.

## What the PR Proposed

Two options forwarded to the client, `reconnect` (default false) and `max_reconnect_attempts` (default null, the
client's own 10), with builder validation, accessors, tests and README text. The transport itself was left as it was:
when the PR was written it never re-dialled, so a client that reconnected by itself was the only way a long-lived
process survived a dropped connection.

## What Was Wrong

Since 5.2.0 (#49) the transport re-dials a Closed client and checks a suspect connection with a PING, and the two
mechanisms fought the client's recovery. Measured in #81 on nats-server 2.14 with client 2.27.0: an operation that
ran into the lost connection started the client's recovery and failed within `request_timeout`; the next operation
PINGed, the client was still Connecting, the PING went unanswered, and the transport called `disconnect()`, which
stopped the recovery, and `connect()`, which with the option on runs the client's whole attempt loop inside the
operation, 109 s at the defaults, for every operation during an outage. Two more findings: a stream lost during the
outage was never recreated with `auto_setup`, because the done flag is cleared only when the transport replaces the
connection and the client's own reconnect left it latched; and a reconnect that gave up discarded the buffered
acknowledgements without a trace, since nothing wired the client's logger.

The four review comments on #81 refined the design: a four-case policy for a failed PING (a passive "keep the
connection" regressed the silent-connection case; an unconditional "replace" ended a connection the client had just
recovered), a worker guarantee limited to polling and bounded by one recovery cycle with `ack_sync` failures still
ending the worker, exhaustion propagating rather than flattened into an empty queue, one re-provision plus one
republish of the same frame for the first publish after a reconnect, a narrow reading of the two 409 statuses with
which the server ends a pull itself (the broad "any 409 other than Consumer Deleted" would have hidden the
configuration errors `Consumer is push based` and `Exceeded MaxRequestBatch`), and the per-request rather than
per-method meaning of `request_timeout`.

## How It Was Adapted

One commit per item, on top of the PR's reconnect commit cherry-picked onto `main` after 5.6.0:

| Commit | Change |
|--------|--------|
| `feat: add reconnect and max_reconnect_attempts transport options` | The PR's reconnect commit, by Petar Fedorovsky, with the placement lines next to it kept |
| `docs: describe what an operation waits for while the client reconnects` | The reconnect hunks of the PR's README commit (c5ccb8d), as the contributor wrote them |
| `feat: with reconnect on, leave the recovery of a lost connection to the client` | A Connecting client is left alone; a failed PING is read against the client's state and reconnect count (`keptAfterFailedPing()`); the count is read only with the option on |
| `fix: with reconnect on, verify provisioning again after the client's own reconnect` | The reconnect count recorded at `setup()`; one `setup()` and one republish of the same frame when the first publish after a reconnect gets 503 "No JetStream responder" |
| `fix: read a pull the server ended for its shutdown or a leadership change as empty` | `409 Server Shutdown` (with the connection check armed) and `409 Leadership Change` are empty batches in both modes; other 409s keep their meaning |
| `feat: hand the application's logger to the NATS client` | An optional PSR-3 logger on the factory, the transport and the builder; the bundle passes `logger` under Monolog's `messenger` channel; `psr/log` a direct requirement |
| `docs: describe the two reconnect modes, their costs and what the transport does in each` | README, CLAUDE.md, STRUCTURE.md, the docblocks; the duplicate default test folded into main's |
| `test: restart the server under a worker and under a sender with reconnect on` | Two Behat scenarios with `docker restart` |
| `test: pin what the mutation run found loose in the reconnect changes` | A redundant flag reset removed; the 409-only reading and the Monolog channel tag pinned |
| `docs: update the test and mutation counts`, `docs: cut the 5.7.0 changelog section` | Release |

## Behavioural Contract

- `reconnect: false` (default): unchanged. The existing connection tests pass as they were, and the client's reconnect
  count is never read.
- `reconnect: true`: a client that reports Connecting is left alone and the operation waits within its own budget. A
  failed PING keeps the connection when the client is recovering or has already recovered, replaces it when the client
  still reports the same connection Open, and dials again when the client is Closed. `auto_setup` verifies
  provisioning again once the reconnect count moved, and a publish that gets the client's 503 after a reconnect gets
  one `setup()` and one more publish of the same frame. Exhaustion propagates as the client's exception. A reconnect
  that gives up with buffered acknowledgements is reported at error level through the wired logger.
- Both modes: `409 Server Shutdown` and `409 Leadership Change` end a pull as an empty batch.
- Left to the client, documented and tracked: a fire-and-forget write that fails on an Open connection recovers
  inline without a budget (client issue #244); a force-reconnect API would let the "Open, same connection" case join
  the client's recovery instead of the transport replacing the connection (same issue).

## Tests

| Area | Tests |
|------|-------|
| Real client over a scripted connection that can go silent, fail a write, close from the server's side, hold or refuse dials | `tests/unit/ReconnectModeTest.php`, seven tests: the operation that runs into the loss starts the recovery and the next waits with no PING and no dial, the operation succeeds when the client recovers within its budget, a silent connection is replaced, a connection the client recovered behind a failed PING is kept, a recovery the PING started is left to run, a pull during the recovery ends empty, a recovery that gives up is reported to the logger and the next operation fails |
| Mocked four-case policy and guards | `testReconnectModeLeavesAClientInTheMiddleOfItsRecoveryAlone`, `testReconnectModeReadsAFailedPingAgainstTheClientsRecovery` (four cases), `testReconnectModeTakesARecoveredConnectionAsChecked`, `testWithoutReconnectAFailedPingReplacesTheConnectionAndTheReconnectCountIsNeverRead` |
| Provisioning after a reconnect | `testReconnectModeVerifiesProvisioningAgainAfterTheClientReconnected` (send, get), `testReconnectModeReprovisionsOnceWhenTheFirstPublishAfterTheReconnectFindsNoResponder` (four variants), `testReconnectModeReprovisionsOnlyOnceForAPublishWithoutAResponder`, `testReconnectModeDoesNotRetryAPublishOutsideTheNoResponderCase` (three cases), `testWithoutReconnectAPublishWithoutAResponderPropagatesAndTheReconnectCountIsNeverRead` |
| The two 409 statuses | `testAPullTheServerEndedForItsShutdownIsEmptyAndTheNextOperationChecksTheConnection` (auto_setup on and off), `testAPullEndedByALeadershipChangeIsEmptyAndTheNextOneDoesNotCheckTheConnection`, `testAShutdownEndingTheRetriedPullAfterReprovisioningIsEmptyToo`, `testA409ReportingAConfigurationErrorStaysVisible` (four cases) |
| Logger wiring | `testBuildPassesTheLoggerToTheClientAndNoneByDefault`, `createTransport_HandsTheFactorysLoggerToTheClient`, `theBundleHandsTheApplicationsLoggerToTheFactory` |
| Options, builder, accessors | The PR's own tests, with `testBuildLeavesReconnectDisabledByDefault` folded into `testBuildTurnsClientReconnectAndPedanticModeOff` |
| Functional | `nats_reconnect.feature`: a worker keeps consuming across a `docker restart`; a message sent after a restart goes out on the reconnected connection and the memory stream is recreated |

## Measurements

nats-server 2.14.2, client 2.27.0, PHP 8.5, defaults (`request_timeout` 10, `connection_timeout` 1), `max_batch_timeout`
1, `auto_setup` with a memory stream, a 53 s `docker stop`. With the client's 10 attempts every operation waited within
its own budget (sends 10 s, a pull 2 s empty, a plain ack buffered at once, `getMessageCount()` 20 s for its two
requests) and none dialled; the first send after the restart took 2.8 s and recreated the stream. With 2 attempts the
client gave up about 12 s in, and every later operation dialled inline for 18.4 s and failed with the client's
exception. The README's Automatic Reconnect section has the table.

## Validation

All local runs used PHP 8.5.11, PHPUnit 13.4.3 and client 2.27.0, in the root and in the functional test app.

- `composer test`: PHPStan level max clean; 669 unit tests, 1,914 assertions.
- `composer test:unit` + `composer coverage:check`: 99.78 % statement coverage (893 of 895).
- `composer test:functional` against the Docker servers (plain, TLS, mTLS, the one that drops idle clients;
  `nats:alpine`, 2.14.2): 60 scenarios, 468 steps, all passed under the strict Behat 4 configuration, the two new
  scenarios restarting the main server with docker among them.
- `composer test:mutation:canary`: 56 of 56 no-op mutants escaped. `composer test:mutation`: 1040 mutants, 1008
  killed, 100 % mutation code coverage, 96.9 % MSI and covered MSI; the 32 survivors are the ones `docs/TESTS.md`
  lists, none new (a first run left three, which the commit "pin what the mutation run found loose" removed or
  pinned). `composer test:mutation:check-log` clean.
- Measurements against a live nats-server 2.14.2 in Docker, see above; no cluster was run.
- CI covers PHP 8.2 with the lowest client release and NATS 2.10, which were not run locally.
