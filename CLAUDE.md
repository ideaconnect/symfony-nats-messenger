# CLAUDE.md

Guidance for Claude Code (and other AI agents) working in this repository.
This file mirrors [AGENTS.md](AGENTS.md) (the cross-tool agent guide) and is the canonical entry point
for Claude Code. For architecture see [STRUCTURE.md](STRUCTURE.md); for human onboarding see [HUMANS.md](HUMANS.md).

## What this is

A Symfony Messenger **transport** for NATS JetStream. It implements `TransportInterface`,
`MessageCountAwareInterface`, and `SetupableTransportInterface`, bridging Symfony's message bus to
NATS via the async `idct/php-nats-jetstream-client` (`^2.28`, amphp-based). It ships
`IDCTNatsMessengerBundle` (auto-enabled by Flex), which registers the transport factory
and the igbinary serializer so projects need no manual wiring.

- **PHP:** `^8.2` · **Symfony:** `^7.2 || ^8` · **NATS:** `^2.9` (`^2.12` for scheduled messages)
- Everything NATS-specific lives behind the `IDCT\NATS\…` client; this library is the synchronous adapter.

## Project memory

Read [docs/PROJECT_MEMORY.md](docs/PROJECT_MEMORY.md) when starting work for maintainer preferences,
the local client-library relationship, behavioral pitfalls, and a dated issue/environment snapshot.
Refresh changeable facts from source and GitHub before relying on them; update the memory when new
findings affect future work. This file remains the project instruction source, with AGENTS.md.

## Commands (prefer composer scripts over ad-hoc invocations)

```bash
composer install                 # install dependencies

composer test                    # PHPStan (level max) + fast unit suite - RUN AFTER EVERY CHANGE
composer analyse                 # PHPStan only
composer test:unit:fast          # PHPUnit only, no coverage
composer test:unit:paused        # PHPUnit, stopped at random moments: fails a test that depends on how fast its process runs
composer test:unit               # PHPUnit with coverage → clover.xml + coverage/ (HTML)
composer coverage:check          # enforce ≥ 90% statement coverage from clover.xml

composer nats:start              # generate the test TLS certs and NKey/JWT credentials, then start NATS in Docker (tests/nats/docker-compose.yaml)
composer test:functional:setup   # install Behat deps (first time only)
composer test:functional         # run Behat scenarios (needs nats:start)
composer examples                # run examples/*.php against the test NATS (needs nats:start)
composer nats:stop               # stop NATS
```

## Definition of done for a code change

1. `composer test` is green (PHPStan **level max**, all unit tests pass).
2. New behavior has unit tests; coverage stays **≥ 90%** (`composer test:unit` + `composer coverage:check`). They also pass
   `composer test:unit:paused`, which CI runs.
3. If transport wiring / Docker / NATS scenarios changed, run the functional suite
   (`composer nats:start` → `composer test:functional` → `composer nats:stop`).
4. Docs updated (see below).

## Conventions

- PHPStan **level max** must stay clean - no new baseline entries; fix the types instead.
- Keep PHPUnit metadata as **attributes** (`#[DataProvider]`, `#[Test]`), not doc-comment annotations
  (doc-comment metadata is deprecated in PHPUnit 11, removed in 12).
- The unit suite runs on PHPUnit 11 to 13: PHP 8.2 installs 11, PHP 8.3 installs 12 and PHP 8.4 or later
  installs 13 (CI runs 11 and 13). Make a test double that sets no expectations with `createStub()`, not
  `createMock()`: PHPUnit 12 and later report a notice for a mock without expectations, and CI's PHP 8.5 job
  fails on it.
- A unit test must not depend on how fast its process runs: a machine short of CPU pauses it for hundreds of milliseconds.
  Give an operation that has to succeed a budget it never uses up and only one that has to time out a short one, hold a
  recovery until the test releases it rather than for a time, and keep a bound on a duration far from what it normally
  takes. `composer test:unit:paused`, in CI, fails a test that breaks this.
- Behat step definitions and hooks are **attributes** (`#[Given]`, `#[When]`, `#[Then]`, `#[AfterScenario]`), and the
  Behat configuration is `tests/functional/behat.php`: Behat 4 reads neither doc-comment annotations nor YAML. The
  configuration is strict, so a scenario with an undefined step fails the run instead of being skipped.
- `TransportOption` is the single source of truth for option keys; every case needs a `DEFAULT_OPTIONS`
  entry in `NatsTransportConfigurationBuilder` (guarded by `testDefaultOptionsCoversAllTransportOptionCases`).
- Mark DSN/credential parameters with `#[\SensitiveParameter]`.
- Use `TypeCoercion` (static helpers) for `mixed → scalar` casting rather than ad-hoc casts.
- Match the surrounding style: typed properties, readonly value objects, rich doc-comments.

## Non-obvious behavior (read before changing the transport)

- **Async client, sync contract.** Client calls return `Amp\Future`; resolve them with `->await()`.
  Symfony Messenger's transport API is blocking.
- **Lazy connection.** No socket opens in the constructor - `jetStream()` connects on first use, and dials
  again once the client has closed (unless `reconnect: true`, it runs with reconnect off, so a lost connection
  leaves it Closed). A
  connection unused for longer than `ping_after_idle`, or one an operation just failed on (not counting
  JetStream replies, but counting a pull the server did not answer), gets a PING first and is replaced if
  it goes unanswered. So does the NAK/TERM of a failed delivery (`reject()`, and `ack()` NAKing a left-out
  retry): the client reads the socket only while an operation waits for a reply, so a close the server sent
  during the handler is unread and a fire-and-forget write would be lost. Since client 2.28.0 the client sees
  such a close on a plain socket before it writes (ACK included); keep the PING for the close it cannot see,
  behind unread bytes (a TLS server's close_notify). `ping_after_idle: 0` turns these
  checks off, and `keepalive()` never pings or dials (it
  runs in a signal handler).
- **Reconnect mode (`reconnect: true`): the client owns recovery.** The transport leaves a Connecting client
  alone (no PING, no dial; the operation waits within its own budget), reads a failed PING by the client's state
  and reconnect count (recovering or already recovered: kept; the same connection silent: replaced; Closed:
  dialled again, which runs the client's attempt loop inline), verifies `auto_setup` provisioning again once the
  count moved and re-provisions once when the first publish after a reconnect gets 503 "No JetStream responder".
  The count is read only with the option on; mocked clients in the default-mode tests have no stats stub. The
  factory's optional logger reaches the client (the bundle passes `logger`); a reconnect that gives up logs the
  discarded buffer at error level. `409 Server Shutdown` and `409 Leadership Change` end a pull as an empty batch
  in both modes. `resend_on_reconnect` maps to the client's `resendJetStreamPublishOnReconnect` (a publish with a
  Nats-Msg-Id written again on the recovered connection); the builder requires `reconnect: true` with it and a
  configured `stream_duplicate_window` longer than `request_timeout`.
- **Logins with a signature.** `jwt` and `nkey` need a signer: the client throws before it writes the CONNECT without one, on
  every server. The transport's one source of a signer is `nkey_seed`, so the builder refuses `jwt` or `nkey` without it, a
  seed next to a username, password or token (a server with both kinds of users ignores them without a word), a blank or
  non-string seed, a seed that is not a user's, a `jwt` that does not have a JWT's three parts, and a credential after a second
  `?` or a `;` in the DSN query. `nkey` stays as an optional check of the seed's key, and an external signer would need it.
  Word such a rule as "no signer", not "no seed". On each `composer test` `LoginCombinationsTest` refuses the 32 combinations
  of a user, token, `jwt`, `nkey` and seed when built, or sends them through the real client as a CONNECT; the other refusals
  are in the builder's tests and `SecretsInExceptionsTest`, and the real logins are in `nats_nkey.feature`,
  `nats_jwt.feature`, `nats_token.feature` and the examples. Test keys are never committed (#9): the unit tests build theirs
  with `TestNkeys`, and `tests/nats/auth/generate.sh` makes the servers' at `composer nats:start`. An option stored in
  `NatsOptions` proves nothing about a login, which is how `jwt` and `nkey` shipped unable to log in.
- **Pull consumers + explicit ACK.** A message is only "processed" once ACK'd. This underpins
  `retry_handler` and shared-consumer load balancing.
- **`get()` reads JetStream 408 and 404 as an empty pull** (no messages); other codes propagate. A
  missing consumer reports 503, not 404: with `auto_setup` it is re-provisioned once, otherwise `get()` throws.
  The client also reports a pull that got no answer before its own deadline as 408 ("No messages received
  within timeout"); that one makes the next operation PING first, since the server did not answer.
  A graceful stop of NATS 2.15 leaves a moment in which a pull gets that same 503, from a consumer the server has already
  stopped (#106). So after a 503 the transport asks for the consumer (`pullMetAServerGoingAway()`): a server that stays
  answers, with the consumer or 404, and the 503 keeps its reading; a server going away leaves the question unanswered
  (Server.Shutdown() drops JetStream API replies before it stops the first consumer) and closes the connection, and the
  pull is an empty batch. No answer on a connection that stays, as for a user not allowed to ask for consumer info, keeps
  the reading too, so a missing consumer is never hidden. With `reconnect: true` the client keeps an unanswered request
  across its reconnect until `request_timeout`, so the wait ends on the connection's state and reconnect count instead.
- **A pull refused at the subscription limit throws `TransportException`.** The transport holds two subscriptions
  per connection (the request reply inbox and the pull's inbox). At a limit of one, the client's
  `SubscriptionLimitException` (client `^2.27`) becomes Symfony's `TransportException` naming the stream, consumer
  and limit, in `fetchBatchMessages()`. The next operation still PINGs first: the server sends the same error when
  an account's limit is lowered and then closes the connection, so the error does not prove the connection is alive.
- **`setup()` create-then-update.** On a stream conflict it reads the live config, **merges** subjects,
  preserves server fields, and updates - it never blindly overwrites an existing stream.
- **Retry strategy:** `retry_handler=symfony` (default) → TERM (Symfony's failure transport retries);
  `retry_handler=nats` → NAK (NATS redelivers), and `send()` skips the copy Symfony's retry sends (a received
  envelope with a retry count), so Symfony's `max_retries` has no effect in nats mode.
- **`keepalive()` queues, never waits.** Symfony calls it from a SIGALRM handler, where fibers cannot switch,
  so it queues `inProgress()->ignore()`, which goes out the next time the event loop runs.
- **Duplicate protection:** a `DeduplicationIdStamp`, added on the first send with `deduplicate` or by the
  application, becomes `Nats-Msg-Id` = `<id>:<topic>:<retry count>`, plus `:failed:<receiver>` on a
  failure-transport copy. JetStream deduplicates per stream, whatever the subject.
- **Serializers strip non-sendable stamps** (`NonSendableStampInterface`, such as the worker's `AckStamp`,
  which holds a closure) before encoding, as Symfony's own serializers do.
- **Scheduled messages:** only when `scheduled_messages=true` does a `DelayStamp` route to
  `{topic}.delayed.{uuid}` with `Nats-Schedule` headers; otherwise the delay is ignored.
- **Serializer security:** the default `IgbinarySerializer` `unserialize()`s payloads - unsafe on
  untrusted subjects. Don't weaken the README security warnings.
- **The bundle registers each service under one id, its class name.** An application's own entry under that id
  (the setup before the bundle) replaces the bundle's. Symfony 7.4+ wraps the serializer each transport names in
  a `SigningSerializer`, one for each service id in the transports' configuration, compared as strings. An alias
  next to the id it points to would wrap it twice for the transport that names the alias, and that transport
  could then never decode a message that must be signed (`RunCommandMessage`, any `sign: true` handler). Don't
  give the bundle's services a second id.

## Documentation maintenance (required, per AGENTS.md)

- `docs/TESTS.md` - update whenever tests are added, removed, or renamed (feature → test map).
- `docs/CHANGELOG.md` - Keep a Changelog format; add items under `## [Unreleased]` as you go.
- `docs/PRs/` - when a PR is merged or adapted, add `PR-NNN-short-description.md`.
- Keep README "Tested by:" references pointing at real test method names.

## Repository facts

- Default branch: `main`. Author commits as the repository owner, never as the agent.
- PRs land with a merge commit, never a squash or a rebase: one commit per item stays readable and revertable, and a
  contributor's commits keep their ids, which is what marks their PR as merged when the work lands through another branch
  (#89 through #101). Bring `main` into a branch by merging it, for the same reason.
- Tasks and findings are tracked as **GitHub issues** (`gh issue …`).
- CI matrix: PHP `8.2` and `8.5`; functional suite runs against plain / TLS / mTLS / operator-mode / token NATS in Docker.
