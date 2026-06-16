# HUMANS.md

Hello, human. 👋 This file is the friendly counterpart to [CLAUDE.md](CLAUDE.md) (for AI agents) and
[AGENTS.md](AGENTS.md) - a quick, no-jargon orientation for people who want to use, build, or
contribute to this library.

## What is this?

**`idct/symfony-nats-messenger`** lets [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
send and receive jobs through [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream) - a fast,
persistent message streaming system. You dispatch a message in Symfony as usual; it travels through
NATS; a worker (`messenger:consume`) picks it up and runs your handler.

If you've used the AMQP, Redis, or Doctrine transports, this is the same idea with a NATS backend.

## Who maintains it

Built and maintained by **IDCT - Bartosz Pachołek** (<bartosz+github@idct.tech>) and contributors.
It's MIT-licensed and developed in the open at
<https://github.com/ideaconnect/symfony-nats-messenger>.

If this saves you time, support is genuinely appreciated:
- ☕ Buy me a coffee - <https://buymeacoffee.com/idct>
- 💝 Sponsor - <https://github.com/sponsors/ideaconnect>

## Prerequisites

- **PHP 8.2+**
- **Composer**
- **Docker** (only for the functional/end-to-end tests - they spin up a real NATS server)
- *(optional)* the **`igbinary`** PHP extension for faster serialization; without it the library
  falls back to PHP's serializer and prints a one-time notice.

## Get started in 5 minutes

```bash
git clone https://github.com/ideaconnect/symfony-nats-messenger.git
cd symfony-nats-messenger
composer install

# Run the fast checks (static analysis + unit tests). No NATS server needed.
composer test
```

That's the inner loop. The unit tests don't touch a network - they run in well under a second.

### Trying the real thing (functional tests)

These exercise the transport against an actual NATS server in Docker:

```bash
composer test:functional:setup   # one-time: install the test app's dependencies
composer nats:start              # start NATS (plain + TLS + mTLS) in Docker
composer test:functional         # run the Behat scenarios
composer nats:stop               # tear NATS back down
```

## Using it in your own project

```bash
composer require idct/symfony-nats-messenger
```

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    transports:
      nats_transport:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic'
        options:
          consumer: 'my-consumer'
          batching: 5
    routing:
      'App\Message\MyAsyncMessage': nats_transport
```

The [README](README.md) has the full configuration guide (TLS, auth, retention, scheduled messages,
consumer strategies, troubleshooting). It's worth a read before going to production - especially the
**Consumer Strategies** and **Security** sections.

## How the project is laid out

A quick tour (the full map is in [STRUCTURE.md](STRUCTURE.md)):

- `src/NatsTransport.php` - the transport itself (send / receive / ack / reject / setup).
- `src/NatsTransportFactory.php` - turns a DSN into a transport.
- `src/Options/` - how DSN + options become validated, immutable configuration.
- `src/Serializer/` - how messages are encoded on the wire.
- `tests/unit/` - fast tests, no server required.
- `tests/functional/` - Behat scenarios against a real NATS server.
- `docs/` - changelog, the test-coverage map, and notes on merged PRs.

## Want to contribute?

Contributions are welcome! The short version:

1. **Open an issue first** for anything non-trivial, so we can agree on direction.
2. Branch off `main`.
3. Make your change **with tests** - unit tests for logic, a Behat scenario if it's end-to-end behavior.
4. Keep it green: `composer test` (PHPStan at max level + unit tests) must pass, and coverage stays
   **at or above 90%** (`composer test:unit` then `composer coverage:check`).
5. Update the docs you touched:
   - `docs/CHANGELOG.md` (Keep a Changelog format, under `## [Unreleased]`),
   - `docs/TESTS.md` if you added/renamed/removed tests,
   - the README if you changed behavior or options.
6. Open a pull request describing what and why.

Don't worry about getting every box perfect on the first push - maintainers will help you get it over
the line. The most important things are a clear problem statement and tests that prove the fix.

## Getting help

- 🐛 **Bugs / features** - open a [GitHub issue](https://github.com/ideaconnect/symfony-nats-messenger/issues)
  with versions (PHP, Symfony, NATS), your DSN/options (with secrets redacted), and what you expected vs. saw.
- 📖 **Usage questions** - check the README's Troubleshooting section first, then open an issue.

Thanks for being here. 💚
