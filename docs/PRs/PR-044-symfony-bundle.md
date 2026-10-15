# PR #44 - Symfony Bundle for the Transport Factory and Serializer

- **Author:** [HypeMC](https://github.com/ideaconnect/symfony-nats-messenger/pull/44)
- **Branch:** `add-bundle-class` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/44
- **Status:** Open - reviewed twice and fixed on the branch `pr-44-fixes`, which merges the contributor's
  commit unchanged and adds one commit per finding, opened as #78 to supersede it.
- **Reviewed at:** commit `0c406a4`, a single commit on `main` at `7019755`, 19 files, +162 / -33

## What the PR Proposes

1. **`IDCTNatsMessengerBundle`**, a thin `AbstractBundle` with no configuration of its own. It registers
   `NatsTransportFactory` as `idct_nats_messenger.transport_factory`, tagged `messenger.transport_factory`,
   and `IgbinarySerializer` as `idct_nats_messenger.serializer.igbinary`, with the serializer's class name
   aliased to it.
2. **`composer.json` type `symfony-bundle`**, the usual type for a bundle. Flex does not need it to enable
   the bundle (see N9).
3. **The functional app runs on the bundle**: `config/bundles.php` enables it, `services.yaml` loses its
   manual factory and serializer entries, and every scenario passes the serializer as
   `idct_nats_messenger.serializer.igbinary`.
4. README, guides, CHANGELOG and TESTS.md describe the bundle; two unit tests load its extension into a
   `ContainerBuilder` and check the definitions.

## First Review: Does the Bundle Give Us Anything?

The question was whether Symfony discovers transport factories on its own, by the interface they
implement, which would make the bundle redundant. It does not. FrameworkBundle registers
`TransportFactoryInterface` for autoconfiguration (`FrameworkExtension.php:701`), but autoconfiguration
only adds the tag to services the application defines. Nothing turns a class in `vendor/` into a service.

Measured in clean Symfony 8.1 skeleton applications with `symfony/messenger`, against a real NATS server,
sending and consuming a message where the setup allowed it:

| Setup | Result |
|---|---|
| `main`, README as written (`composer require` and a DSN) | `No transport supports Messenger DSN "nats-jetstream://..."`; only Symfony's `sync` and `in_memory` factories carry the tag |
| `main`, the README's `serializer:` value | The container does not build: no service has that id |
| `main` plus bare `NatsTransportFactory: ~` and `IgbinarySerializer: ~` entries | Works: autoconfiguration adds the tag to the factory the application defined |
| PR #44, README as written, `services.yaml` untouched | Works: Flex added the bundle to `config/bundles.php` ("From auto-generated recipe") |
| PR #44 plus the manual entries | Works: two tagged NATS factories, the first one wins |

So the bundle is needed: the README's Installation section, which never mentions registering the
factory, does not work on `main` as written, and the bundle makes it work for every Flex application.

## Second Review: Verdict

**Request changes.** One blocking issue (B1) and fourteen non-blocking ones, two more confirmed but left
out of the report as too small (N15, N16). PHPStan level max and the unit suite pass on the PR at Symfony
8.0.15 and 7.4.21, and Infection kills the three mutants the bundle adds. Found with the code-review
skill at maximum effort: ten finder angles, an adversarial verifier for each candidate, and a final gap
sweep. B1 was then reproduced again by hand against a real NATS server.

## Blocking Issue

### B1 - Two ids for the serializer wrap it for signing twice

Symfony 7.4 added message signing. At container build time Messenger collects the serializer id each
transport names, removes duplicate strings, and wraps each id in its own `SigningSerializer` decorator,
`.signing.<id>` (`MessengerPass` and `FrameworkExtension`, `array_unique` over the ids). It never checks
whether an id is an alias, so the PR's alias and the service it points to count as two ids and get two
decorators. Decorating a name means "this name now leads to the decorator, which wraps what the name led
to before"; the alias led, by name, to `idct_nats_messenger.serializer.igbinary`, which the second
decorator has taken over. The chains, printed from a real application's transports:

```
nats    serializer: 'IDCT\NatsMessenger\Serializer\IgbinarySerializer'
        SigningSerializer -> SigningSerializer -> IgbinarySerializer
failed  serializer: 'idct_nats_messenger.serializer.igbinary'
        SigningSerializer -> IgbinarySerializer
```

Sending works: each layer writes the same `Body-Sign` header. Receiving fails for every message that must
be signed: the outer layer checks the signature and removes the header before handing the message down,
and the inner layer then finds a signed type without a signature and refuses it: `Message
"...RunCommandMessage" requires a signature but none was found`. The types that must be signed are those
of handlers tagged `sign: true`: Symfony's own `RunCommandMessage`, `RunProcessMessage`, the scheduler's
`ServiceCallMessage` and `RedispatchMessage`, and any application handler marked that way. Ordinary
messages pass both layers.

| Symfony | What happens to a signed message on the transport naming the alias |
|---|---|
| 8.1.9 | Becomes a decoding failure and goes to the failure transport; the handler never runs (reproduced by hand) |
| 8.0.15 | `decode()` throws `InvalidMessageSignatureException`; `get()` TERMs the message and rethrows, the worker stops |
| 7.2, 7.3 | Not affected: no signing |

It takes two ids in one application, which the PR's own documents invite: the README names the class,
STRUCTURE.md and the whole functional suite name `idct_nats_messenger.serializer.igbinary`. With one id on
every transport there is one wrapper (control run: the same message handled). The newer Symfony code the
review looked at wraps each transport's serializer separately instead of decorating the service by id,
which would remove the problem upstream, but every release from 7.4 to 8.1 has it.

## Non-Blocking Issues

| ID | Finding |
|---|---|
| N1 | The class-name alias is also how autowiring finds `IgbinarySerializer`, and once a transport names the serializer the signing decorator takes that name over, so a service that type-hints the concrete class receives a `SigningSerializer` and throws a `TypeError` |
| N2 | The README's "only when the bundle is not enabled" snippet registers the serializer and not the factory, so following it without the bundle still fails with "No transport supports Messenger DSN" |
| N3 | The changelog tells upgraders they no longer need their `services.yaml` entries, but the bundle does not provide the `igbinary_serializer` id the README documented from 2.0.0 to 5.4.1; deleting that entry breaks the container |
| N4 | Flex adds the bundle on a plain `composer update` from 5.4.x, not only on install, and leaves it in `config/bundles.php` on a downgrade, after which the application and every Composer run that clears the cache fail; nothing says so |
| N5 | The factory has no class-name id, so code that autowires or decorates `NatsTransportFactory` breaks once the manual entry is gone, and an upgrader's kept entry adds a second factory instead of replacing the bundle's |
| N6 | The unit tests load the extension into a bare, never compiled `ContainerBuilder` and restate its four lines; tagging the factory `container.excluded`, or renaming its tag in the bundle and the test, keeps them green while every NATS DSN fails |
| N7 | The functional suite swapped one undocumented serializer id for another and never uses the class name the README tells users to write, though README.md cites its TERM scenario for that configuration |
| N8 | Removing the manual entries from the functional app deleted the only test of the setup every upgrading application has, and nothing guards STRUCTURE.md's promise that it keeps working next to the bundle |
| N9 | Flex finds the bundle only by its class name, its place in `src/` and the literal `AbstractBundle` import in its file; the composer `type` plays no part, and nothing guards any of this |
| N10 | No CI run exists for the PR head: the fork run waits for approval with zero jobs, so the functional suite the definition of done asks for has not run |
| N11 | The bundle is the only class in `src/` without a doc-comment, and two of its three `@param` lines repeat native types |
| N12 | TESTS.md's README example map has no row for the new `config/bundles.php` example and misses the bundle test the README cites for the igbinary example |
| N13 | The README's unit test count changed, but the mutation counts (826 of 858) in README.md, TESTS.md and `infection.json5` did not, though the PR adds three mutants |
| N14 | The Behat context spells the serializer id out five times (two defaults, three YAML templates), and the TLS step's default can never be used |
| N15 | Cut from the report: the unit tests' `hasDefinition()` and `hasAlias()` assertions add nothing to the `getDefinition()` calls after them |
| N16 | Cut from the report: the bundle's factory sorts last in Messenger's factory list, about 10 µs per process; raising its priority has its own downside |

## What Is Good

- **The idea is right and needed** (see the first review): it turns the README's two-line installation
  from broken into working for every Flex application, with no configuration of its own to maintain.
- **`AbstractBundle` with `loadExtension()`** is the current, minimal way to write it; FrameworkBundle is
  already a hard dependency, so it needs no new requirement.
- **Upgrades are safe by construction:** an application's own definitions win over a bundle's, so the
  manual entries kept by upgraders keep working (checked on compiled kernels).
- **The docs discipline held:** README, guides, CHANGELOG, STRUCTURE.md and TESTS.md were all updated in
  the same commit.

## Resolution

Every finding was addressed on the branch `pr-44-fixes`. It starts from `9fa715d`, `main` after #77, which
requires client `^2.27`, merges the contributor's commit `0c406a4` as it is (`796b3ee`; the conflicts kept
both sides: in `CLAUDE.md` the client version from `main` and the PR's sentence about the bundle, in
`docs/CHANGELOG.md` and `docs/TESTS.md` the entries of both), and adds one commit per item on
top, so each can be reviewed, reverted or cherry-picked on its own. Each fix has a test that fails on the code before it,
where the item is about behaviour; the commit messages say which.

### The blocker

| Commit | What |
|--------|------|
| `89b19c0` | **B1.** The serializer has one id, its class name, the README's value. An application's own entry under that id replaces the bundle's. The new test gives a transport each id the bundle gives the serializer and checks one `SigningSerializer` around it and a signed round trip; against the PR it fails, and the round trip alone throws "requires a signature but none was found" on Messenger 8.0.15. |

### Code and tests

| Commit | What |
|--------|------|
| `76118ad` | **N6.** The bundle tests boot a FrameworkBundle and Messenger kernel (`tests/Support/MessengerTestKernel.php`) and take the transports from its container: a NATS DSN gets a `NatsTransport` with the bundle and none without it, and the README's igbinary example works with the bundle alone. Tagging the factory `container.excluded`, or renaming its tag, now fails them; no NATS server needed. Also resolves N15. |
| `cf19441` | **N14.** The Behat context names the igbinary serializer once, in a constant; the dead default is gone. |
| `62ea4c8` | **N7.** The functional suite passes the README's class name everywhere; Gherkin keeps the backslashes. |
| `88543e8` | **N5.** The factory has one id, its class name. Tests: an application service autowires it, and an application's own entry leaves exactly one NATS factory. Both fail against the PR. |
| `6553452` | **N8.** A guard test compiles the entries applications used before the bundle (factory with its tag, serializer under its class name and `igbinary_serializer`) with and without the bundle. |
| `8f17f7d` | **N9.** A guard test repeats Flex's class guess against `composer.json` and `src/`; writing the `AbstractBundle` import as a grouped `use` fails it and nothing else. |

### Documentation

| Commit | What |
|--------|------|
| `639b9dd` | **N2.** The Installation section shows the factory entry for applications without the bundle. The new test compiles both "only without the bundle" snippets and the igbinary example straight from README.md; against the old README it fails with "No transport supports Messenger DSN". |
| `eaccda1` | **N3.** A README section "Upgrading from 5.4 or earlier" and the changelog say which entries can go and that `igbinary_serializer` needs the class name first. No alias for the old id: a second id is what B1 is about. |
| `d28e7fd` | **N4.** Flex on update and on downgrade, measured with Flex 2.11 and Symfony 8.1: the update from 5.4.1 adds the bundle; the downgrade to 5.4.1 keeps the line, `cache:clear` fails, Composer exits 255; and a later update does not add it back, because the package's `symfony.lock` entry stayed. |
| `da9e171` | **N11.** The class doc-comment records the three constraints (Flex's class guess, one id per service, registering does not select); the redundant `@param` lines are gone. |
| `4425393` | **N12.** The README example map covers the `bundles.php` example, the bundle tests on the igbinary row, the `services.yaml` snippets and upgrade notes, and Flex's own behaviour as checked by hand. |
| `4514166` | **N13.** Test count, coverage and mutation counts measured on the branch: 548 tests, 99.74% of statements, 835 of 867 mutants killed (96.3%), eleven mutants of headroom in `infection.json5`. |

### Not changed

- **N1.** Symfony 7.4 to 8.1 decorate a transport's serializer under the id the transport names, and
  autowiring by class uses that id, so a service type-hinting the concrete `IgbinarySerializer` gets the
  decorator whichever single id the bundle uses. The same has been true for every application that
  registered the serializer by hand since 7.4. Registering it only under an internal id would turn the
  `TypeError` into a build error, at the cost of changing the value the README tells everyone to write;
  the class name was kept. A service that needs the serializer can type-hint `SerializerInterface` and
  have the service injected by id.
- **N10.** The fork's CI run still waits for approval and was not approved here. The branch was checked
  locally instead, the way CI checks it (below).
- **N16.** Left as it is.

### Verification of the branch

After the rebase onto `9fa715d` (2026-10-15), with client 2.27.0 and the dependencies CI's PHP 8.5 job
resolves:

- `composer test` at `4514166`: PHPStan level max clean, 548 tests / 1481 assertions.
- `composer test:unit` and `composer coverage:check`: 99.74% of statements (768 of 770).
- Infection, as CI runs it: the canary (46 of 46 mutants that change nothing escaped), the run (867
  mutants, 835 killed, 96.3% MSI and covered MSI, 100% mutation code coverage, the 32 documented survivors
  and none in the bundle), and the log check (no mutant killed by the test harness).
- Not run locally after the rebase: the functional suite, the examples, and the PHP 8.2 run with the
  lowest client. CI runs all three on every push.

Before the rebase, at `a460343`, when the branch started from `bbc8238` (client `^2.24`):

- `composer test` at `9047db2`: PHPStan level max clean, 539 tests / 1414 assertions, with the dependencies
  CI's PHP 8.5 job resolves: FrameworkBundle and Messenger 8.0.15 (Behat holds Console and
  DependencyInjection at 7.4.21) and client 2.27.0.
- The same with the dependencies resolved for PHP 8.2 and the lowest client, as CI's PHP 8.2 job does:
  Symfony 7.4.21 and client 2.24.0, 539 tests passing, the signing test included. Before the branch moved
  onto the `^2.24` requirement, the same run passed with client 2.10.3.
- `composer test:unit` and `composer coverage:check`: 99.74% of statements (760 of 762), the bundle 100%.
  The two uncovered statements pre-date the PR.
- Infection, as CI runs it, on the branch before it moved onto the `^2.24` requirement, with the same
  `src/`, tests and installed dependencies: the canary (46 of 46 mutants that change nothing escaped), the run (860
  mutants, 828 killed, 96.3% MSI and covered MSI, 100% mutation code coverage, the 32 documented survivors
  and none in the bundle), and the log check (no mutant killed by the test harness).
- The functional suite against nats-server 2.14.2, plain, TLS and mTLS, with client 2.27.0: 52 scenarios,
  411 steps, all passing. The igbinary features were also run after each commit that changed what they use. Not run
  against the oldest NATS image (2.10), which CI's `functional-oldest-nats` job covers; nothing on the
  branch touches stream or consumer configuration.
- `composer examples`: 5 passed.
- B1 by hand on Symfony 8.1.9 against a real NATS server: with the two ids a signed `RunCommandMessage` went
  to the failure transport while an ordinary message next to it was handled; with one id it was handled.
- Flex 2.11 by hand, in scratch applications: a fresh install and an update from 5.4.1 enable the bundle,
  a downgrade to 5.4.1 keeps the line and fails, and an update after that does not add it back.

### Left for the maintainer

- #78 supersedes #44, as #43 did for #40. HypeMC's commit is kept unchanged as the merged parent, and #44
  itself is left open.
- The branch first started from `bbc8238`, the client `^2.24` requirement that was pending on `main`,
  committed on `chore/require-client-2.24`. Once #77 brought `main` to `^2.27`, it was rebased onto
  `9fa715d` without that commit (2026-10-15); the tip before the rebase was `a460343`.
- Unrelated to the PR, seen while testing it: on Symfony 8.1, `messenger:consume` logs a deprecation
  because `NatsTransport::get()` lacks the `int $fetchSize` argument `ReceiverInterface` will require in
  the next major version. No issue filed yet.
