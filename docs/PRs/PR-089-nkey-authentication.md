# PR #89 - NKey Authentication

- **Author:** [Brajk19](https://github.com/ideaconnect/symfony-nats-messenger/pull/89) (Adrian Brajković)
- **Branch:** `nkey-authentication` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/89
- **Status:** Merged on 2026-11-06 through [#101](https://github.com/ideaconnect/symfony-nats-messenger/pull/101), merge commit
  `3760c1c`. Reviewed and adapted on the branch `feat/89-nkey-jwt-auth`, which keeps the contributor's commits unchanged and
  adds one commit per item. It ships in 5.8.0.
- **Reviewed at:** commit `bef7115`, on `main` at `30b5685`, 9 files, +305 / -3

## What the PR Proposes

1. **The `nkey_seed` option.** The builder turns it into the client's `NkeySeedSigner`, hands that to the client as
   its nonce signer, and sends the public key derived from the seed. A NATS user the server knows only by its NKey can
   connect, with no user or password. An invalid seed is refused when the transport is built, and the message does
   not repeat it.
2. **The `nkey` option deprecated.** Setting it triggers `E_USER_DEPRECATED`, naming 5.8 and removal in 6.0. A value
   other than the seed's public key is refused at build. The PR's description itself doubted the deprecation, for the
   reasons below.
3. **Tests and docs.** Seven unit test methods, a Behat scenario `nats_nkey.feature` with an NKey user added to
   `tests/nats/nats.conf`, a README section, and entries in `docs/TESTS.md` and `docs/CHANGELOG.md`.

## Verdict

**Request changes, adapted here rather than sent back.** The feature works and is worth having: it is the first way
`jwt` or `nkey` could ever connect. Two things blocked the merge, neither in the contributor's code: `main` had moved on,
and a Symfony release had turned the unit suite red on every branch. The other findings are about a seed silently changing
who a connection is, an empty value silently meaning no seed, and where the seed leaks when something fails.

## Why `jwt` and `nkey` Were Thought to Work

They never did. Since `v4.0.0` the transport has passed both options to the client with no signer, and the client throws
`requires a nonce signer` before it writes the CONNECT, on every server. None of the 22 tags here passes a signer, and all
67 client tags throw. The one working use found is an application subclass that replaces the protected `$client` with its
own signed client.

Nothing could notice. The only unit test asserted that the strings reach `NatsOptions`. The rule linking them to a signer
lives in the client's `encodeConnect()`, whose own tests pass a signer. The functional suite, the examples and every test
server used a password or mTLS. The client's `encodeConnect()` could ignore every credential, or throw on all of them, and
`composer test` still passed. So this adopts the rule "a value stored in `NatsOptions` proves nothing about a login": JWT
and NKey now have real logins in Behat and the examples, and `LoginCombinationsTest` crosses that boundary on every
`composer test`, with no Docker.

## Why `nkey` Stays, Without a Deprecation

- A public key cannot answer the server's nonce, so `nkey` alone can never log in. That is why it is refused with no
  signer. It is not a reason to remove it.
- `NonceSignerInterface` has only `sign()`, so a later external signer needs `nkey` to name the public key. An out-of-process
  signer worked through a prototype with `nkey` as the transport's only auth option, on NATS 2.9.25 to 2.15.
- A deprecation would have cost more than it gave. Nothing on the enum reaches a YAML or DSN user. `#[\Deprecated]` on the case
  is ignored on PHP 8.2 and 8.3, and on 8.4 and later, with the builder as it is, hits every user and fails the unit suite on
  8.5. The PR's `@trigger_error` is logged at INFO only, and its "since 5.8" names a version whose changelog section is already
  closed without this feature. Removing
  the option with no guard would turn a hard failure into a silent anonymous or other-credential connect, since unknown
  options are ignored.

So the rule is worded by what is missing, a signer, and `nkey_seed` is today's one source of one. External signers and `.creds`
files are planned as separate issues: a signer adds a source of its own, and a `.creds` option replaces `jwt` and `nkey`.

## What Was Measured

By hand, against real servers on one machine, so the timings are localhost timings.

| Question | Result |
|---|---|
| Does a seed log in? | `setup()`, `send()`, `get()`, `ack()` on NATS 2.9.25, 2.10.29, 2.11.17, 2.12.15, 2.14.7 and 2.15.1 |
| After a restart? | The login is signed again with the new server's nonce. With `reconnect: true` nothing fails, for a send soon after the last one; in default mode the first send fails with "Reconnect is disabled" and the next one works, exactly as for a password user. After a few seconds of idle the first send can fail with the raw `StreamException` in the client follow-up, with or without a seed. A worker can end if a pull lands in the last moments of the stop on NATS 2.15, with or without a seed: see `294b6d9` |
| TLS? | Plain TLS, `tls_handshake_first` and mTLS with a client certificate all work |
| A cluster? | With `reconnect: true` and a DSN naming only the leader, stopping the leader, the next send logged in on a server the DSN never named, in 12 ms |
| `jwt`? | `jwt` + its seed works on an operator-mode server. A bearer JWT connects with any seed, since the server does not check the signature, so "`jwt` needs a seed" is the client's rule, not a security check |
| A JWT's expiry? | At expiry the server sends `User Authentication Expired` and closes. A running worker stops with that message in both modes. A new connection with the same JWT gets `Authorization Violation`, which does not mention the expiry |
| A seed next to a password? | The server picks the NKey without a word, even with a wrong password, and on a two-account server the transport moved account. On a server that sends no nonce the client refuses the login, and a server in operator mode refuses it |
| An empty seed? | Read as no seed: as admin with DSN credentials, as guest with `no_auth_user`, anonymously on an open server. An array holding a valid seed too |
| Who does refusing a lone `nkey` or `jwt` affect? | No working login, unless a subclass that replaces `$client` still passes these options, which must stop. It does make a dead transport visible earlier: a plain `messenger:stats` exits 1 instead of showing 0, a worker on several transports stops at start, and `catch (\RuntimeException)` around a dispatch misses it |
| Where does the seed leak? | With `zend.exception_ignore_args=0`: the frames of the factory, the constructor and the builder; the chained client exception of a mistyped seed, which a tool like Sentry records whole; the mismatch error's message whatever that setting says. Typed after a second `?` or a `;`, also the TLS library's error for a file name, and the server itself, for a consumer name or a description. As a `jwt`, the CONNECT, which a tracing server logs |

## What Is Good

- **The right client API, and the right place for it.** The seed goes in `nonceSigner`, so every reconnect signs the
  new nonce with no help from the transport, and nothing about reconnect mode had to change.
- **Refusing at build, not at connect,** for a bad seed and a contradicting `nkey`. This work extended that rule; the PR
  set it.
- **A real end-to-end test.** The Behat scenario sends and consumes over connections that authenticate with the seed
  alone, and it passes unchanged on this branch.
- **The docs discipline held:** README, `docs/TESTS.md` and the changelog all came with the code.

## Resolution

The branch starts from the PR head `bef7115` with the contributor's commits unchanged. It merges `main` at `5cd9ffa`
(`893ba47`; the visible conflict was at the top of `docs/CHANGELOG.md`, and the PR's `### Deprecated` had merged without a
marker into the `[5.8.0]` section, so both of its sections went under `[Unreleased]`, and `9c1dc53` then removes the
deprecation), merges `4ff0b0f`, the test fix below (`de548ad`), and adds one commit per item on top. Each fix has a test that
fails on the code before it, where the item is about behaviour. The commit messages up to `1241209` say which, and which tests
are guards that pass on it too; each later fix adds or tightens the test that fails.

The tests of each item are in `docs/TESTS.md`, under "NKey authentication", "The `nkey` option", "The `jwt` option", "Secrets in
Exceptions", "Login Combinations" and the sections of the NKey, JWT and token features.

### Blockers

| Commit | What |
|--------|------|
| `4ff0b0f` | **The bundle test,** branched from `main` at `5cd9ffa`, as it is not this PR's defect: Symfony Messenger 7.4.23 and 8.1.11 changed the message the bundle test expects. It passes on 7.3.0, 7.3.10, 7.4.22, 7.4.23, 8.0.15, 8.1.10 and 8.1.11. |
| `893ba47` | **The merge with main,** described above. |

### The `nkey` option, and what the build refuses

| Commit | What |
|--------|------|
| `9c1dc53` | The deprecation is removed. |
| `1bf1d9b` | `nkey` with no signer is refused. It could never connect, and with `reconnect: true` it took 43 s to say so. |
| `ac3a6f8` | The mismatch error names the seed's key, not the value. A seed in `nkey` printed whole, whatever the ini says. |
| `122bd87` | `jwt` with no signer is refused. |
| `7153bc6` | `jwt` with its seed is tested and documented. |
| `88e69ee` | A seed next to a username, password or token is refused, from the DSN or the options. `jwt` stays allowed. |
| `89bc006` | A blank or non-string seed is refused. Only null means no seed, and `%env(default::NATS_NKEY_SEED)%` gives null for an optional one. |
| `6bfbc76` | A seed that is not a user seed is refused. |
| `9fb4f74` | A `jwt` without a JWT's three parts is refused. A seed or a whole `.creds` file there would have gone to the server in the CONNECT, which this branch would otherwise have introduced. |
| `cf6e9f2`, `2dacdd7`, `49bc5b1` | A credential after a second `?` or a `;` in the DSN query is refused, as it stayed in the value of the option before it. The query is read as `parse_str()` reads it, so a space next to the name or another case does not get past the check. `49bc5b1` pins the pattern against changes that would refuse working logins. |
| `ecce9d8`, `193c3aa`, `5e3d3c0`, `fb80175` | A `max_reconnect_attempts` float past the range of an int is refused. It wrapped around and failed in the client's constructor, whose frame holds every credential, became an unrelated huge number, or became 0. `ecce9d8` set a limit of 2147483647, and `193c3aa` narrowed it to that float, as larger ints and numeric strings work on `main`. `fb80175` adds a numeric string past the largest float, which became 0. |

### Where a secret could end up

| Commit | What |
|--------|------|
| `313073d`, `d6a4a8d` | A mistyped seed no longer stays in the exception chain. The client's message goes on and its exception does not. `d6a4a8d` does the same for a sodium function the client cannot call, whose `\Error` holds the seed in its frames. |
| `e197c80` | `resolveNkey()` and `resolveJwt()` mark both parameters. The signer in a frame would print the raw secret key. |
| `974cef3` | Every parameter on the build path that holds the DSN or the options is marked, with a test of each place that throws and a reflection guard. |
| `bed717d` | So are the helpers that take one value, as a seed typed after a numeric option stayed in `toNumber()`'s frame. |
| `412bddd`, `1241209`, `884ed8a`, `daaf974` | A stream or topic name error, and the errors that print an option's value, repeat it only if it is at most 50 plain characters, so a query typed by mistake stays out and a plain mistake is still shown. Short values pin the characters that keep a short credential out. |
| `3d025de` | The seed's text leaves the option map. A partial gain: the signer's `secretKey` still prints as raw bytes. |
| `4cc8217` | The Behat config, which holds a scenario's seed and JWT and stays behind after a killed run, is ignored by git. |

### Real logins

| Commit | What |
|--------|------|
| `efefc4f` | `TestNkeys` builds the unit tests' keys when they run. |
| `d17633e` | `tests/nats/auth/generate.sh` makes the servers' keys at `composer nats:start`, as no private key belongs in the repository (#9). |
| `3380133` | That generator fails loudly and leaves no half-made set. |
| `dbb5ec1`, `efdd431`, `c96f8cf` | An operator-mode server and a token server, in the test stack and in CI. |
| `b1ea9fe`, `22d0a40`, `17646ed` | The examples `nkey-login.php`, `jwt-login.php` and `token-login.php`. Each expects the server to refuse a wrong credential, so a server that lets anyone in would not pass. |
| `9b9de61`, `181ce83`, `b2d61e3`, `e5fe273`, `5e46d08`, `5172efc` | Behat: logins on real servers, in the README's `%env()` forms, over TLS and mTLS, a bearer JWT, an expired JWT, an account without JetStream, a server restart under NKey and JWT, and the refusals through `messenger:setup-transports`, `messenger:stats` and `messenger:consume`. |
| `363728e` | **The restart scenarios now lose a live connection.** On CI's runners the worker's first connection came after the restart, so nothing reconnected and they would have passed with re-signing broken, on `main`'s scenario too. The step waits for the connection, and a new step checks the reconnect. Proved with a client that reuses its first signature. |
| `294b6d9` | **The step pauses 300 ms after the connection.** With a live connection at the restart, and docker as fast as on CI's runners, the scenarios hit a race that is on `main` too: a graceful stop of NATS 2.15 leaves a moment, about 10 ms for each durable consumer on a file stream, in which a pull can get 503, which ends the worker. The README names the gap, and the fix is left for a follow-up. |
| `542b953` | `LoginCombinationsTest`: all 32 combinations of a user in the DSN, a token, `jwt`, `nkey` and a seed are refused when built, or go through the real client as a CONNECT that carries them, with a signature that verifies. |

### Smaller changes

| Commit | What |
|--------|------|
| `090abf7` | The test of an `nkey` repeating the seed's key now also requires the signer. |
| `6f1e394` | CI and `composer.json` declare sodium, so that losing it fails instead of skipping. |
| `0a8ddb3`, `fce0758`, `1989a95`, `264ceb9`, `e63d23e`, `0f16c0d` | A list is not shown as a value; a value ending in a newline is not repeated; missing test credentials say so at once; the mistyped seed reaches the client's checksum, and the check of the console output can see it; the reflection guard covers parameters named after a credential. |
| `d4d88c0`, `e16a445` | The error for a seed next to a password is accurate, and says what decides it: a server that sends no nonce. |
| `518ae15`, `d6a4a8d` | The error for a value that is not a seed says what a seed looks like, and says nothing of the kind when the fault is in sodium. |
| `bad2c7d`, `19304f1`, `daaf974` | Tests pin the whole message of the two last refusals, and an empty first or last part and the padding of a JWT. |
| `4920406`, `3d6b336` | `composer test:functional` has no process timeout, as the added scenarios bring it to about Composer's 600 seconds, and it still has to run Behat. |
| `2c1b2af` | A test and two scenarios pin the 0 a refused login reads as, and the docs say when the count runs the attempt loop. |

The other commits are documentation, some with a test that pins a statement: the README's NKey and JWT sections and
its upgrade note, `docs/TESTS.md`, `docs/CHANGELOG.md`, `STRUCTURE.md`, `AGENTS.md`, `CLAUDE.md`, `HUMANS.md`, the project
memory, the functional suite's README and the examples' guide, with one commit for two blank lines.

### Not changed

- **The contributor's seed stays in `b6b3684`.** No server here trusts it after `d17633e`, and it was already public in the PR.
  A scan of the range reports it there and nowhere else, so a scanner run on this history will flag that commit once.
- **Unknown options.** `nkeySeed`, `nkey-seed` and `?NKEY_SEED=` are ignored, as is any unknown option.
  Refusing unknown keys can break existing configurations, so it needs its own change, left for a follow-up issue.
- **A lone DSN user.** `tok@host` is sent as a username. A DSN user next to a password option is a working setup
  on `main`, so any mapping would need its own change, and only where no password resolves. In the same follow-up.
- **The signer's secret key, and the client's frames.** `NkeySeedSigner`'s `secretKey` prints in a dump, and the client marks no
  parameter. Both are changes in the client. So is the wording of "requires server nonce from INFO".
- **A blank `jwt`, `token` or `nkey`** still reads as unset. That predates the PR. In the same follow-up.
- **A bearer JWT with a seed that is not a user seed** is refused with the rest, though it would connect. The option is new
  and nothing depends on it.
- **`messenger:stats` reads a refused login as 0.** It is older than this PR, and changing it changes what stats reports.
- **Not in the suite, measured by hand:** a cluster failover with a seed, the 43 seconds on a server that sends no nonce, and a
  JWT that expires under a running worker. The examples build a transport with no container, so the `%env()` forms have
  scenarios and no example.
- **Not tested at all:** an auth callout service that reads both a password and an NKey, which the refusal in `88e69ee`
  would block; a real network between client and server; JetStream domains, leaf nodes or account exports with a restricted
  user; `jwt` + `nkey_seed` in a cluster failover.

### Verification of the branch

Measured at `294b6d9`, as the commits after it change only text: the counts below and corrections in the docs, and a comment in the
step. CI's three jobs ran in its order, in fresh clones, with a short PATH, so that docker reaches the server in about 40 ms, against
about 20 ms in CI's logs, on NATS 2.15.2, which `nats:alpine` is at this time, and 2.10.29.

- PHP 8.5.11, client 2.28.0, Symfony FrameworkBundle and Messenger 8.1.11, PHPUnit 13.4.6: `composer test` has PHPStan level
  max clean and 1036 tests / 2965 assertions, and coverage is 99.80% of statements (1016 of 1018). PHPStan and the unit suite
  also pass at each commit on top of the merges.
- PHP 8.2.35, the lowest client allowed (2.28.0), Symfony 7.4.23, PHPUnit 11.5.57: `composer test` and coverage give the same.
- Infection, as CI runs it: the canary (all 63 mutants that change nothing escaped), the run (1176 mutants, 1158 killed, 98.5%
  MSI and covered MSI, 100% mutation code coverage), and the log check (no mutant killed by the test harness). Of the 18
  documented survivors, the 3 new ones are in the catch for a sodium failure. The tests that catch them run in a child process,
  which the mutant does not reach; each was made by hand and fails a plain `composer test`.
- `composer test:functional`: 93 scenarios, 696 steps, all passing, on PHP 8.5 in 10m3s, past Composer's limit of 600 seconds, and
  on PHP 8.2 in 9m55s. On 2.10.29, the image of CI's `functional-oldest-nats` job, without `@delayed` as that job runs it: 89
  scenarios, 657 steps, in 9m7s. On 2.9.25, which the README allows and CI does not run: the NKey feature without TLS (18
  scenarios) and the JWT feature (10).
- `composer examples` in strict mode: 14 of 14 in each of the three jobs. Three examples failed now and then at that time, on `main`
  too, and this branch changed none of their files, so a red examples step naming one of them said nothing about it. `keepalive`
  did on any version: it read the count right after a plain ACK, and it failed 2 of 8 runs of `main` on 2.10.29 and 2 of 60 runs on
  2.15.2. The two others did on 2.10.29, with `no suitable peers for placement, peer offline`, where a later iteration asked for
  three replicas right after the one before it restarted a server: `handler-failing-during-failover` failed 2 of 20 runs of it
  alone on `main` and 1 of 20 here, and in a setup like CI's oldest job `handler-succeeding-during-failover` failed alone in 5
  of 19 runs here and in 1 of 10 on `main`, where all three failed in one more. [#102](PR-102-flaky-tests.md) fixed all three, and
  went into this branch before it landed.
- The restart scenarios at CI's speed, restarting through Docker's API with the worker's boot at a random phase of the step's poll,
  30 runs of each of the three on 2.15.2: 10 of 90 failed without the pause of `294b6d9`, all with `JetStream pull request ended
  with status 503`, and 0 of 90 with it. With the pause and busy CPUs, the stores on ext4 or PHP 8.2 limited to 2 CPUs: 0 of 309. A
  runner really short of CPU can still get there: with 4 busy loops inside a 2-CPU quota 7 of 30 and 5 of 30 failed with the same 503.
- The same scenarios on 2.15.1 and 2.15.2, with a client changed to reuse its first signature and a worker that boots 1.5 s late, as on CI's runners:
  with the steps before `363728e` they pass, and only `Connected` is logged. With this step they fail with `Authorization
  Violation`, and with the real client they pass, logging `Reconnected`.
- `LoginCombinationsTest` with the client changed: ignoring credentials gives 9 failures, throwing on JWT and NKey 4 errors
  and 1 failure, signing other bytes 4 failures, dropping the JWT 2 failures.
- gitleaks 8.28.0 over the range: 4 findings, all in `b6b3684`: the contributor's seed twice and its public key twice. The tip
  holds no key.
- On GitHub: CI passed on the first run of #101, and again once #102 had gone into the branch: 1038 unit tests, also stopped at random moments,
  coverage 99.80%, 1158 of 1176 mutants killed, Behat 93 of 93 on each PHP job and 89 of 89 on NATS 2.10.29, and 14 of 14 examples in
  each job. The merge commit marked #89 as merged.

### Left for the maintainer

- **Follow-ups,** left for separate issues: `.creds` files and rotation, external signers, and the smaller findings, among
  them the client's dumps and frames, a raw `StreamException` on a send after a restart, a pull in the last moments of a
  graceful stop on NATS 2.15, the `^7.2` constraint that cannot
  load `NatsTransport` (the real floor is 7.3.0, since 5.0.0), and a rule that a new option must reach a real server in a test.
- **The least permissions of a restricted user,** measured on client 2.28.0 and not in the README:
  - Subscribe: `_INBOX.>`.
  - To send: the topic, plus `{topic}.delayed.*` with `scheduled_messages`.
  - To consume: `$JS.API.CONSUMER.MSG.NEXT.{stream}.{consumer}` and `$JS.ACK.{stream}.{consumer}.>`.
  - For message counts: `$JS.API.CONSUMER.INFO.{stream}.{consumer}`.
  - For `setup` and `auto_setup`: `STREAM.CREATE.{stream}`, `STREAM.INFO.{stream}`, `STREAM.UPDATE.{stream}`,
    `CONSUMER.INFO.{stream}.{consumer}` and `CONSUMER.CREATE.{stream}.{consumer}`, under `$JS.API`.

  Worth a separate issue if restricted users are promoted: without `MSG.NEXT` a worker looks idle with no error, and
  without `$JS.ACK` `ack()` returns normally and the message comes back after each `ack_wait`, so a handler runs repeatedly.
