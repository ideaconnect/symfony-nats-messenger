# Project memory

Established on 2026-10-09 by reading both local repositories, transport source and tests,
Composer manifests, CI, changelogs, and the open GitHub issue lists. This is a durable starting
point for future sessions, not a replacement for source or current issue status.

## Maintainer context and scope

- Bartosz owns both this Symfony transport and its underlying `idct/php-nats-jetstream-client`.
- Transport checkout: `/home/bartosz/dev/idct/symfony-nats-messenger`.
- Client checkout: `/home/bartosz/dev/idct/php-nats-jetstream-client`.
- GitHub repositories: [transport](https://github.com/ideaconnect/symfony-nats-messenger) and
  [client](https://github.com/ideaconnect/php-nats-jetstream-client).
- The maintainer explicitly permits upgrading or improving the client when needed for transport
  work. Issues in both repositories are often solved together. Investigate the root cause in the
  appropriate library instead of treating the client as an unchangeable external dependency.
- Local `gh` is permitted; the maintainer also reports a linked GitHub connector. Use available
  capabilities, with `gh` as a practical option for repository and issue reads.
- This authorization does not make every upstream issue part of the current task. Avoid unrelated
  upgrades, releases, or edits. Follow the client's own `AGENTS.md` when working there and respect
  session filesystem permissions (the sibling checkout may need write approval).
- Preserve pre-existing work. At initial inspection, this repository had an untracked `promo/`;
  the client had several untracked planning/audit files, `.claude/`, `behat.yml`, `composer.lock`,
  and `promo/`. Recheck `git status` in both repositories before editing.
- Existing agent guidance says to author commits as the owner, never as the agent. Do not invent
  author identity. Tasks and findings are tracked in GitHub issues.

## Where knowledge already lives

| Reference | Purpose |
|---|---|
| [AGENTS.md](../AGENTS.md) | Project instructions and required checks/documentation |
| [CLAUDE.md](../CLAUDE.md) | More detailed agent conventions and transport pitfalls |
| [STRUCTURE.md](../STRUCTURE.md) | Architecture and repository layout |
| [HUMANS.md](../HUMANS.md) | Contributor onboarding |
| [README.md](../README.md) | Public behavior, options, retry/consumer strategies, security |
| [docs/TESTS.md](TESTS.md) | Feature-to-test map, examples, mutation testing caveats |
| [docs/CHANGELOG.md](CHANGELOG.md) | Releases and reasons for behavior changes |
| [docs/PRs/](PRs/) | Notes on previously adapted PRs |
| Client `AGENTS.md`, `README.md`, `TESTS.md`, `CHANGELOG.md` | Upstream workflow, APIs, coverage and changes |

Prefer source and manifests when documentation disagrees. For example, this transport now also
implements `KeepaliveReceiverInterface` and `CloseableTransportInterface`, and includes a
`Stamp/` directory and `StreamCompression` enum beyond the abbreviated AGENTS structure.

## Architecture and ownership boundary

`NatsTransportFactory` selects `nats-jetstream://` or `nats-jetstream+tls://` and constructs
`NatsTransport`. The configuration builder parses `/stream/topic`, merges method options over
DSN query parameters over defaults, validates them, and constructs a configured `NatsClient`.
`NatsTransportConfiguration` is an immutable readonly value object. Public PHP namespaces are
`IDCT\NatsMessenger\` here and `IDCT\NATS\` in the client.

The transport translates Symfony envelopes, stamps, retry semantics, and synchronous receiver
calls into client operations. Client methods return `Amp\Future`; transport calls normally
`await()` them. The client owns socket/TLS/authentication handling, protocol parsing, subscription
and request inboxes, JetStream API requests, publish acknowledgements, and acknowledgement tokens.

Start in these locations for a bug:

| Symptom | Transport entry point | Client entry point |
|---|---|---|
| Options, credentials or wrong units | `Options/NatsTransportConfigurationBuilder.php`, configuration accessors, `TypeCoercion.php` | `Connection/NatsOptions.php`, `Auth/`, `Transport/` |
| Send fails, duplicate or scheduled copy | `NatsTransport::send()`, `natsMessageId()`, `Stamp/DeduplicationIdStamp.php` | `JetStreamContext::publish()`, `Schedule.php`, request handling |
| Empty/missing batch or pull error | `get()`, `fetchBatchMessages()`, `recoverFromFetchFailure()` | `JetStreamContext::fetchBatch()`, guarded subscriptions in `NatsConnection` |
| Stale connection or failure recovery | `connectIfNeeded()`, `connectionUsable()`, `awaitOnConnection()` | `Core/NatsClient.php`, `Connection/NatsConnection.php`, connection states |
| Stream/consumer setup changes | `setup()`, stream update helpers, `resolveReplayPolicy()` | `JetStream/Configuration/`, API/model handling |
| Retry/ACK/keepalive | `reject()`, `sendNak()`, `ack()`, `keepalive()` | `ack()`, `ackSync()`, `nakWithDelay()`, `term()`, `inProgress()` |
| Serialization failure | `Serializer/AbstractEnveloperSerializer.php`, `IgbinarySerializer.php` | Header codec only if wire headers are involved |

The transport uses one-shot `fetchBatch()`, explicit ACKs, and `disconnect()`. It does not use
`PullConsumerIterator::handle()`, client graceful `drain()`, KeyValue, ObjectStore, or services.
Its builder explicitly disables automatic reconnect and pedantic mode. Assess upstream changes
against these actual paths; many iterator/drain/reconnect issues have different preconditions.

## Behavior to preserve

- **Connections:** construction opens no socket. First use connects; a Closed client is dialled
  again. An idle connection (strictly longer than `ping_after_idle`) or a failed operation calls
  for an RTT PING before reuse. An unanswered PING causes replacement. The wait is bounded by
  `connection_timeout`, or the client's shorter `request_timeout`. `ping_after_idle: 0` disables
  idle and post-failure checks, but Closed-state recovery still applies. `close()` resets the
  connection and provisioning state; the same transport remains reusable.
- **Pull status:** 408 is empty; 404 is historically empty. A missing consumer normally gives 503,
  and deletion during a pull can give 409. With `auto_setup`, 404/409/503 cause one provisioning
  attempt and one retried pull; repeated 409/503 propagate. The client's own unanswered-pull
  deadline is also a JetStream 408, distinguished by the prefix `No messages received within
  timeout`. It triggers a check next time. A server-answered empty pull counts as connection use.
- **Delivery identity:** outgoing `TransportMessageIdStamp` is a UUID; incoming it holds the
  JetStream reply/ACK subject. ACK, reject and keepalive depend on that incoming token. Messages
  without it are skipped; empty payloads are TERMed to avoid an endless poison-message loop.
  Decode failures reject according to retry mode and preserve the original decode exception
  even if rejection fails. Received headers with integer array keys are omitted before decode.
- **Retry ownership:** default `retry_handler=symfony` sends TERM; Symfony owns retries and the
  failure transport. `retry_handler=nats` sends NAK, optionally delayed; `send()` suppresses
  Symfony's retry copy when it carries `ReceivedStamp` and a positive retry count. NATS owns
  redelivery through `nak_delay`, `backoff`, `max_deliver`, etc. Publishing both copies formerly
  multiplied failures (#47). Symfony delay stamps only take effect with scheduled messages on.
- **Keepalive:** Symfony invokes it from SIGALRM. It queues `inProgress()->ignore()` without
  awaiting, pinging, or connecting, because a signal handler cannot switch fibers (#48).
  The event loop must run for the queued write to leave. Plain blocking PHP work therefore
  still needs an `ack_wait` long enough to cover the handler.
- **Deduplication:** `deduplicate` adds a sendable `DeduplicationIdStamp` before encoding.
  An application-supplied stamp works with the option off too. Wire ID is
  `<id>:<topic>:<retry count>`, plus `:failed:<original receiver>` for a failure copy. JetStream
  deduplicates per stream; subject and original receiver prevent legitimate copies being dropped.
  Automatic stamps cannot deduplicate a newly constructed envelope after an uncertain publish;
  applications must retain a stable ID for that. The 5.4.1 ID change matters in rolling upgrades.
- **Scheduling:** enabled positive delays publish to `{topic}.delayed.{uuid}`, with `Nats-Schedule`
  and target headers. Round up to the next whole second to avoid early delivery. Setup manages
  `{topic}.delayed.>` and `allow_msg_schedules`; disabling scheduling removes that transport's
  pattern and clears the flag where it existed. Requires NATS >= 2.12.
- **Provisioning:** create first, then fetch existing configuration on a JetStream create failure
  and merge/update when it exists. Do not string-match server conflict wording. Preserve unknown
  fields and existing subjects. Storage, retention and an existing consumer's replay policy stay
  at their live values. Existing deny-delete/deny-purge cannot be undone. Preserve replicas when
  not configured. The four older retention limits are authoritative, including clearing them;
  newer `max_msg_size`/`max_consumers` are preserved unless configured. Clamp inherited duplicate
  windows to finite max age; encode empty `metadata`/`consumer_limits` as objects. Older NATS
  versions reject some updates that newer ones accept.
- **Serialization:** strip `NonSendableStampInterface` before encoding (#46); worker `AckStamp`
  contains a closure. A custom serializer takes precedence; otherwise choose igbinary if installed
  or `PhpSerializer` with a warning. Both defaults deserialize PHP objects and require trusted
  subjects. Keep the documented security guidance intact.
- **Stats:** consumer `num_pending + num_ack_pending`, falling back to stream messages and then 0.
  Stream count can include already acknowledged messages; outage and empty queue both read as 0.
  A failed initial connection gets one dial attempt, not a second for the fallback (#54).

## Cross-library development and verification

1. Read current diffs and relevant instructions in both repositories. Confirm the installed client
   with `composer show idct/php-nats-jetstream-client`; a sibling checkout is not automatically
   the dependency in `vendor/`. Do not edit vendor files.
2. Reproduce at the transport/client boundary and assign the fix to the library owning the behavior.
   Add regression coverage there and transport integration coverage when observable behavior changes.
3. For a coordinated unpublished client fix, use an isolated Composer path-repository/test checkout
   when useful; don't accidentally commit workstation paths or development-only constraints.
4. Check both root and functional-app dependency versions. `tests/functional/composer.json` has its
   own `vendor/` and client requirement; its autoloader reads transport code directly from `../../src/`.
5. `TransportOption` defines keys; every case needs a builder default. Prefer `TypeCoercion` and
   sensitive DSN/credential parameters. Update README examples and `docs/TESTS.md` with test changes,
   `docs/CHANGELOG.md` under Unreleased, and `docs/PRs/` when a PR is merged or adapted.

Transport checks: `composer test` (PHPStan max on `src` plus fast unit tests) after code changes.
Coverage: `composer test:unit`, then `composer coverage:check` (>= 90%). Live transport changes:
`composer test:functional:setup`, `composer nats:start`, `composer test:functional`, and relevant
`composer examples`; stop the fixtures afterwards. TLS certificates are generated, not committed.
Fixtures expose plain/TLS/mTLS/stale-idle NATS on 4222/4223/4224/4225, and a three-server JetStream cluster
`east` for the examples on 4226-4228 (containers and server names `nats_cluster_1..3`; two servers tagged
`disk:ssd`, one `disk:hdd`). Examples that stop or restart a server with docker start it again. Measured on it
(2026-10-19, client 2.27.0): after a graceful `docker stop` of a stream's leader (nats 2.15.1 and 2.10.29) a
`reconnect: true` worker failed over with no empty pull; after `docker kill` (2.15.1) the first send timed out after
`request_timeout`, or failed with 503 "No JetStream responder" while the stream had no leader. The DSN names one
server (`servers: [$server]`), so a worker started while that server is down cannot connect. CI runs PHP 8.2 with the lowest
allowed client and PHP 8.5 with latest, verifies root/app versions match, and also tests NATS 2.10
without delayed scenarios. README advertises NATS ^2.9; the oldest CI image is actually 2.10.

Transport unit tests use `DG\BypassFinals` for final client mocks, `Future::complete/error`,
`TestableNatsTransport`, and `RuntimeTestableNatsTransport`. `UnansweredPullTest` exercises the real
client with an in-memory scripted server. It distinguishes a client's SUB-fence PING from the
transport's connection-check PING (PR #75). Changing client wire behavior can require adapting
this harness even when transport behavior stays the same.

Mutation testing has a specific harness trap: Infection's file interceptor can displace
BypassFinals and make final-class mock failures look like killed mutants. `tests/bootstrap.php`
handles this. Run `composer test:mutation:canary` before `composer test:mutation`, and
`composer test:mutation:check-log` after; floors are MSI 90 / covered MSI 95. Older reported
100% scores were invalid; do not cite them as current evidence.

Client checks differ: PHPStan level 8 on source and tests (`composer stan`), PHPUnit unit suite
(`composer test:unit`), integration gated by `RUN_INTEGRATION=1`, and `composer test:e2e` for relevant
JetStream/wire changes. Client fixtures use 14222–14229 rather than the transport's ports. Follow
its AGENTS guidance about JWT fixture generation and the mutating fixture-validity check.

## Dated snapshot: 2026-10-09

- Transport checkout: `main`, HEAD `7019755`; changelog's last release is 5.4.1 (2026-10-05).
  Unreleased contains the unanswered-pull test adjustment for client 2.19.0's fence PING.
- Client checkout: `main`, HEAD `6d9da48`; changelog starts at 2.24.0 (2026-10-09).
  Local checkout/source, published release, and installed dependency are separate facts.
- After the initial orientation, Bartosz upgraded the root dependency and raised its requirement
  to `^2.24`. Installed and locked versions are both `v2.24.0`, source reference
  `6d9da48a6d175d626f4238a280af5edfb2052743`; the earlier root `v2.8.0` mismatch is resolved.
- The functional app is still separate: its manifest requires `^2.10.3`, and its installed client
  reports `dev-main` at `0074c1c0f8ea07410dcf6c0b29ee64fe216a0450` (June 2026). Align its
  constraint and installation with the root before using functional results to assess 2.24.0.
- `composer test` passed again after the upgrade on PHP 8.5.11 / PHPUnit 11.5.57:
  PHPStan clean; 528 tests, 1364 assertions. The initial pre-upgrade baseline passed too.
  These results do not validate functional tests, coverage, or mutation scores; those were not
  run for this documentation/upgrade verification. Session permissions now allow network and
  filesystem access without approval; the earlier PHPStan socket restriction no longer applies.
- One open transport issue was returned:
  [#76: rejected pull inbox at the subscription limit](https://github.com/ideaconnect/symfony-nats-messenger/issues/76).
  The issue reports that, with a per-connection subscription limit of 1, the shared request inbox
  occupies the slot required for a pull inbox: `get()` leaks a client `ConnectionException`, and
  a caller keeping the transport pays a needless next-operation PING although the server answered
  and the connection stays Open. It reports reproduction on transport `7019755` / client 2.24.0,
  both fetch-read and heartbeat-read shapes; at a limit of 2 it works. A clearer Symfony
  `TransportException` and no redundant health check would improve diagnostics, not make a
  one-slot worker able to consume. Read the live issue/implementation brief before fixing;
  these findings were read from the issue, not reproduced in this orientation.
- Relevant upstream issue families from the open list include
  [#196](https://github.com/ideaconnect/php-nats-jetstream-client/issues/196) (batch results and fatal
  errors with reconnect), [#204](https://github.com/ideaconnect/php-nats-jetstream-client/issues/204)
  (request-inbox rejection wake-up), and
  [#188](https://github.com/ideaconnect/php-nats-jetstream-client/issues/188) (batch subscribe deadlines
  during reconnect). Check their preconditions: reconnect is disabled here. The iterator/drain
  reports, including [#213](https://github.com/ideaconnect/php-nats-jetstream-client/issues/213),
  concern APIs the transport does not currently use. The historical client #175 fix is linked
  from transport #76 and introduced prompt rejection reporting and the SUB fence.

Refresh this snapshot with `git status`, `git log`, Composer package versions, and `gh issue list`
in both repositories. Keep lasting decisions and newly learned pitfalls here; leave detailed
reproductions, implementation briefs, and changing issue status in their GitHub issues.

## Issue #76 review: 2026-10-14

- GitHub #76 remains open, last updated 2026-10-09; remote transport main remains `7019755`.
  The working-tree root and functional manifests both require `^2.24`, unlike the committed
  `^2.10.3` used by the issue's brief. Root currently installs client `v2.26.8` (`b139ef3`);
  the functional app installs `v2.24.0` (`6d9da48`). Recheck these before further work.
- Both of the brief's scripted real-client pull tests still reproduce the exception and extra
  `rtt()` on 2.26.8. The heartbeat shape now has a previous `ConnectionException` too; it is
  not necessarily a client exception with no previous cause. No typed inbox-rejection exception
  exists in this installed version.
- A separate NATS 2.12.15 fixture confirmed the own-read failure with a shared request inbox
  at `max_subscriptions: 1`: repeated pulls fail promptly, the client remains Open, and the
  second pull performs one `rtt()`. At 2, consumption and the next empty pull succeed without
  a check. The brief's prototype maps the limit to Symfony `TransportException` and removes
  the check at 1, and still consumes at 2.
- Review concern, not a maintainer decision: the proposed substring-based suppression also
  applies when a runtime account-limit reduction sends the same error and then closes the
  connection. A focused mocked regression (pull fails while client still reports Open, then
  send on that transport) passes on current source and fails with the brief's prototype:
  the send uses the dead connection instead of checking and replacing it. The issue acknowledges
  this trade-off. Prefer retaining checks for ambiguous errors, or improving client rejection
  classification before suppressing them; add a closing-during-pull regression. The proposed
  diagnostic's unconditional "The connection stays open" is unsafe for that same case.
- `composer test` passed on the current checkout (PHPStan max; 528 tests, 1364 assertions).
  The prototype also passed the existing 528 tests and both scripted rejection tests, showing
  the existing suite does not cover that recovery regression. Review experiments were isolated
  under `/tmp/symfony-nats-issue-76-review`; no production code was changed, no GitHub comment
  was posted, and the temporary Docker fixtures were stopped and removed. Functional-suite,
  coverage, and mutation gates were not run for this review.

### Refined recommendation for #76

The recommended final design is a coordinated client/transport fix: add a public
`SubscriptionLimitException extends ConnectionException` in the client for the existing
ConnectionException-producing limit paths (raw server error and synthesized inbox/replay
errors), then translate it in the transport's `fetchBatchMessages()` with the original cause
and stream/consumer context. Keep `awaitOnConnection()`'s normal post-failure health check.
Require the first client release containing the type in both Composer manifests; the typed
API is proposed, not implemented or released.

A typed exception identifies the reason, not connection liveness or definite ownership of
an inbox rejection. NATS 2.12.15's `server/client.go` uses the same limit error for a rejected
SUB and an account-limit update; `applyAccountLimits()` schedules closure 20 ms after sending
it. Even a fence PONG is not proof that a close has not been scheduled. Remove the unconditional
no-PING acceptance criterion rather than weakening recovery to satisfy it. Keep diagnostics
neutral about connection state and avoid claiming a specific inbox was certainly rejected.

A scratch transport-only prototype using the existing text recognition but retaining the
health check passed PHPStan max, the existing 528 tests (1364 assertions), and three focused
rejection/recovery tests (14 assertions). This validates the transport behavior; it does not
validate the proposed upstream exception API. The test artifacts remain under
`/tmp/symfony-nats-issue-76-review` (`ConservativeNatsTransport.php` and related tests).

On 2026-10-14, at the maintainer's request, this recommendation and revised acceptance criteria
were added to the body of GitHub #76 as “Recommended ultimate solution (reviewed 2026-10-14)”.
The section explicitly supersedes the older text-matching/no-PING proposal and acceptance
criteria; the original report and implementation brief remain preserved. The published body
was fetched again and verified against the prepared content.

The maintainer's five caveats were reviewed and answered in
[#76 comment 6157966331](https://github.com/ideaconnect/symfony-nats-messenger/issues/76#issuecomment-6157966331)
on 2026-10-14. The reply recommends the client-first release sequence, identifies the upstream
issue/release as a prerequisite still to be linked, assigns replay tests to the client, and
calls for documenting the error-listener's new exception type. It also includes proposed
summary/title wording and a supersession banner for the old brief; those edits were suggested
in the reply, not applied. `muxDroppedException()` belongs in the typed client paths too.

Three further testing/wording points were answered in
[#76 comment 6158096906](https://github.com/ideaconnect/symfony-nats-messenger/issues/76#issuecomment-6158096906)
on 2026-10-14. The reply revises the earlier mandatory live account-JWT-update requirement:
use required scripted protocol tests with the real client for error/EOF before and after
inbox confirmation, plus actual envelope delivery/acknowledgement at limit two and both
rejection paths at limit one. Establish the real shared request inbox and enforce the cap.
A scripted EOF must be `TransportClosedException`, not an empty string; synchronize closure
explicitly rather than asserting an exact 20 ms delay. Live JWT-update integration is an
optional client follow-up, with no new transport Docker fixture required. The proposed opening
retains the no-loss/no-hang impact statement, scoped to the reproduced limit-one case.
These are posted recommendations; the tests and proposed issue-summary edits are not implemented.

## PR #78 review: 2026-10-14

- Reviewed [#78](https://github.com/ideaconnect/symfony-nats-messenger/pull/78) at `a460343`
  against remote main `7019755`. It supersedes #44 and includes the client `^2.24` requirement
  commit `bbc8238`. It was open at review time; the bundle is not yet part of remote main.
- The proposed `IDCTNatsMessengerBundle` registers the factory and igbinary serializer under
  their class names. Application definitions under those same ids override the bundle's.
  Keep the serializer under one id: Symfony 7.4/8.0 signing decorates configured ids separately,
  so an alias and its target used by different transports can produce two signing wrappers.
  The PR tests a signed round trip through the compiled container and covers legacy definitions.
- Flex discovers this bundle using its PSR-4 namespace, root-level class filename and literal
  Symfony bundle-base-class reference. The Composer `symfony-bundle` type alone is not the
  discovery mechanism. The README documents the legacy `igbinary_serializer` migration and
  the bundle/symfony.lock entries that Flex leaves behind on downgrade.
- No actionable regression found. In an isolated checkout, `composer test` passed with PHP
  8.5.11, FrameworkBundle/Messenger 8.0.15 and client 2.26.8: PHPStan max clean, 539 tests,
  1414 assertions. Verified [CI run 38576570046](https://github.com/ideaconnect/symfony-nats-messenger/actions/runs/38576570046)
  is successful for this head, including PHP 8.2/lowest client, PHP 8.5/latest client,
  functional suites, coverage, mutation checks and the NATS 2.10 job. Functional and mutation
  suites were not rerun locally for this review. No GitHub review/comment was submitted.
