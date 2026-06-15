# CLAUDE.md

Guidance for Claude Code (and other AI agents) working in this repository.
This file mirrors [AGENTS.md](AGENTS.md) (the cross-tool agent guide) and is the canonical entry point
for Claude Code. For architecture see [STRUCTURE.md](STRUCTURE.md); for human onboarding see [HUMANS.md](HUMANS.md).

## What this is

A Symfony Messenger **transport** for NATS JetStream. It implements `TransportInterface`,
`MessageCountAwareInterface`, and `SetupableTransportInterface`, bridging Symfony's message bus to
NATS via the async `idct/php-nats-jetstream-client` (`^2.4`, amphp-based).

- **PHP:** `^8.2` · **Symfony:** `^7.2 || ^8` · **NATS:** `^2.9` (`^2.12` for scheduled messages)
- Everything NATS-specific lives behind the `IDCT\NATS\…` client; this library is the synchronous adapter.

## Commands (prefer composer scripts over ad-hoc invocations)

```bash
composer install                 # install dependencies

composer test                    # PHPStan (level max) + fast unit suite — RUN AFTER EVERY CHANGE
composer analyse                 # PHPStan only
composer test:unit:fast          # PHPUnit only, no coverage
composer test:unit               # PHPUnit with coverage → clover.xml + coverage/ (HTML)
composer coverage:check          # enforce ≥ 90% statement coverage from clover.xml

composer nats:start              # start NATS in Docker (tests/nats/docker-compose.yaml)
composer test:functional:setup   # install Behat deps (first time only)
composer test:functional         # run Behat scenarios (needs nats:start)
composer nats:stop               # stop NATS
```

## Definition of done for a code change

1. `composer test` is green (PHPStan **level max**, all unit tests pass).
2. New behavior has unit tests; coverage stays **≥ 90%** (`composer test:unit` + `composer coverage:check`).
3. If transport wiring / Docker / NATS scenarios changed, run the functional suite
   (`composer nats:start` → `composer test:functional` → `composer nats:stop`).
4. Docs updated (see below).

## Conventions

- PHPStan **level max** must stay clean — no new baseline entries; fix the types instead.
- Keep PHPUnit metadata as **attributes** (`#[DataProvider]`, `#[Test]`), not doc-comment annotations
  (doc-comment metadata is deprecated in PHPUnit 11, removed in 12).
- `TransportOption` is the single source of truth for option keys; every case needs a `DEFAULT_OPTIONS`
  entry in `NatsTransportConfigurationBuilder` (guarded by `testDefaultOptionsCoversAllTransportOptionCases`).
- Mark DSN/credential parameters with `#[\SensitiveParameter]`.
- Use `TypeCoercion` (static helpers) for `mixed → scalar` casting rather than ad-hoc casts.
- Match the surrounding style: typed properties, readonly value objects, rich doc-comments.

## Non-obvious behavior (read before changing the transport)

- **Async client, sync contract.** Client calls return `Amp\Future`; resolve them with `->await()`.
  Symfony Messenger's transport API is blocking.
- **Lazy connection.** No socket opens in the constructor — `jetStream()` connects on first use.
- **Pull consumers + explicit ACK.** A message is only "processed" once ACK'd. This underpins
  `retry_handler` and shared-consumer load balancing.
- **`get()` swallows JetStream 404 and 408** (consumer-missing / timeout-empty) as empty results;
  other codes propagate.
- **`setup()` create-then-update.** On a stream conflict it reads the live config, **merges** subjects,
  preserves server fields, and updates — it never blindly overwrites an existing stream.
- **Retry strategy:** `retry_handler=symfony` (default) → TERM (Symfony's failure transport retries);
  `retry_handler=nats` → NAK (NATS redelivers).
- **Scheduled messages:** only when `scheduled_messages=true` does a `DelayStamp` route to
  `{topic}.delayed.{uuid}` with `Nats-Schedule` headers; otherwise the delay is ignored.
- **Serializer security:** the default `IgbinarySerializer` `unserialize()`s payloads — unsafe on
  untrusted subjects. Don't weaken the README security warnings.

## Documentation maintenance (required, per AGENTS.md)

- `docs/TESTS.md` — update whenever tests are added, removed, or renamed (feature → test map).
- `docs/CHANGELOG.md` — Keep a Changelog format; add items under `## [Unreleased]` as you go.
- `docs/PRs/` — when a PR is merged or adapted, add `PR-NNN-short-description.md`.
- Keep README "Tested by:" references pointing at real test method names.

## Repository facts

- Default branch: `main`. Author commits as the repository owner, never as the agent.
- Tasks and findings are tracked as **GitHub issues** (`gh issue …`).
- CI matrix: PHP `8.2` and `8.5`; functional suite runs against plain / TLS / mTLS NATS in Docker.
