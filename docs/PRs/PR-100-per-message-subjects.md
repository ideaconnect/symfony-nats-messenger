# PR #100 - Per-Message Subjects

- **Author:** [Brajk19](https://github.com/ideaconnect/symfony-nats-messenger/pull/100) (Adrian Brajković)
- **Branch:** `dynamic-subjects` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/100
- **Status:** Open. The review opened #110, #111 and #112, which the contributor answered on the branch
  (`ece1b53`, `b99bf0d`, `75c27e0`). A maintainer round on the same branch then merged `main` (`752772e`) and added
  the commits listed below; the contributor's commits stay as they are.

## What the PR Proposed

1. **`NatsSubjectStamp`.** The topic in the DSN stays the default publish subject; a stamp publishes one message to
   another subject, such as `shop.orders.updated.42` for one entity.
2. **`additional_subjects`.** The patterns those subjects fall under. `setup()` adds them to the stream, and the
   transport's one durable consumer filters on the topic and the patterns with `filter_subjects` (NATS 2.10+), so
   every message of the transport still goes through one consumer, in stream order.
3. **`NatsReceivedSubjectStamp`.** `get()` records the subject a message arrived on, so that Symfony's retry goes
   back to it.
4. **`Nats-Msg-Id` names the subject** (`<id>:<subject>:<retry count>`), so that one deduplication id sent to two
   subjects is stored twice.

## How It Is Implemented

- **The publish subject** (`resolvePublishSubject()`, #110). A stamp wins only when the transport's consumer reads
  its subject, the topic or a match of a pattern: Symfony hands every transport a message is routed to the same
  envelope, so a transport that does not read the subject publishes to its topic. Then, for Symfony's retry
  (`isSymfonyRetry()`: a `ReceivedStamp` and a retry count above 0), the received subject; then the topic. A
  failure-transport copy goes to that transport's topic. A delayed stamped message waits under
  `{topic}.delayed.{uuid}` and is delivered to the stamped subject. `get()` adds `NatsReceivedSubjectStamp` only with
  the option.
- **The stamp checks its subject** when it is made: empty, whitespace, an empty token, or a `*` or `>` in a token is
  an `InvalidArgumentException`.
- **The builder checks the entries:** one that is not a subject pattern, or that overlaps the topic, another entry
  or, with `scheduled_messages`, `{topic}.delayed.>`, is refused by name, since JetStream would refuse the stream.
- **`setup()` keeps what the consumer has** (#111). It writes the union of the configured filters and the live
  consumer's, leaving out a filter another one covers, as it keeps the stream's subjects. A process whose
  configuration lacks a pattern, as a rollout or a rollback runs it, does not narrow the consumer. This holds for
  every transport: without the option too, a topic renamed in the DSN is added beside the old one, where 5.10.1
  replaced it. Removing a pattern, or an old topic, stays a manual step (README, "Removing a pattern").
- **A server before NATS 2.10** (#112 and this round). `setup()` refuses `additional_subjects` there before any
  stream or consumer request, after connecting, since `messenger:setup-transports` calls it on a transport that has
  not connected. Without the option such a server gets `filter_subject` with the topic, as from 5.10.1: the union
  would need `filter_subjects`, which it ignores. A server that reports no release is let through.
- **The rollout** is two steps, as a process of 5.10.1 or older narrows the consumer to the topic whenever it runs
  `setup()` (README, "Rolling it out and back").

## The Commits of the Maintainer Round

| Commit | What |
|---|---|
| `971491a` | Merge of `main` (`752772e`). Two conflicts: `setup()` read the live consumer on both sides, the branch's read kept, ahead of the filters, `main`'s dropped; and the README's "Upgrading from 5.10.1", `main`'s two items and "Tested by" line kept, the branch's item after them. The consumer configuration sets the filter right after the durable name again, so the request keeps 5.10.1's field order, which `main`'s tests of #145 compare byte for byte |
| `69e012a` | The topic alone for a server before 2.10; the eight escaped mutants of the PR's code (below); the bound on `pause()` in `JetStreamInjectedNatsTransport`, and `LoginCombinationsTest` checking that its client does not reconnect, for the mutant that errored and the two that timed out |
| `b9c6c0e` | The changelog and README: what changes without the option, the refusal of an overlapping pattern, the new tests in `docs/TESTS.md` and the "Tested by" lines, the mutation numbers |

## What Was Measured

By hand, against real servers on one machine, with a script that runs `setup()` on topic `a`, then on topic `b`
with the same stream and consumer name, and reads the consumer after each.

| Question | Result |
|---|---|
| A renamed topic, `main` (5.10.1) | nats-server 2.9.25, 2.10.29 and 2.15.3, clients 2.30.1 and 2.33.0: the consumer's `filter_subject` goes from `a` to `b`. 2.9.25 takes a changed `filter_subject` |
| A renamed topic, the branch before this round | 2.10.29 and 2.15.3: `filter_subjects` `[b, a]`. 2.9.25 with client 2.30.1: the consumer is left with no filter (`num_pending` counts the whole stream), then `setup()` fails with "Consumer filter subject does not match the configured topic."; with client 2.33.0: the client refuses the request ("nothing was sent"), and every `setup()` fails |
| A renamed topic, after this round | 2.9.25, both clients: `filter_subject` `b`, as on `main`. 2.10.29: `[b, a]` |
| `additional_subjects` on 2.9.25 | Refused before anything is created: "The 'additional_subjects' option requires NATS Server >= 2.10, but the connected server reports 2.9.25." |
| A pattern replaced by one that overlaps it (`orders.*.updated`, then `orders.eu.>`) | 2.10.29 and 2.15.3: `setup()` fails at the stream update with the server's `subject "orders.*.updated" overlaps with "orders.eu.>"`; the stream and the consumer are left as they were. Any configured pattern goes onto the stream first, so this refusal comes before the consumer's own refusal of overlapping filters |

## Mutation Testing

Infection on the merged head before `69e012a`: 1960 of 1996 mutants killed, 33 escaped, one fatal error and two
timeouts. The eight escaped beyond `main`'s 25 were all in the PR's code: a dead `array_values()`, the two `sort()`
calls of the consumer check (they only ordered its message), the connect before the version is read, the two
servers let through (no version, an unreadable one), the `scheduled_messages` message next to
`additional_subjects`, and the builder's overlap through a `*`. The fatal error was `continue 2` made `continue 3`;
the check now calls `matchedByOneOf()`. The timeouts were `whileTheClusterSettles()` without its `return` in a test
double without the 60-pause bound, and a builder without `reconnectEnabled`, under which a login test waited out
every reconnect attempt. After it: 1969 of 1994 killed, the 25 survivors `docs/TESTS.md` lists, none timed out or
errored.

## Tests

- **Unit** (`tests/unit/SubjectRoutingTest.php`, `tests/unit/Stamp/NatsSubjectStampTest.php`, the builder's
  `testBuildRefusesAnAdditionalSubjectSetupCouldNotUse`): the publish subject, retries and the failure transport,
  delayed messages, `Nats-Msg-Id`, the received subject, the stream subjects and consumer filters, the servers
  before 2.10 and those let through; `docs/TESTS.md`, "Per-Message Subjects", lists them.
- **Behat** (`tests/functional/features/nats_subjects.feature`): one consumer reading the topic and a stamped
  subject, a delayed stamped message, Symfony's retry keeping the subject, a stamped message routed to two
  transports, and the filters of an existing consumer widened, kept and not replayed.

## Left Open

- **Removing a pattern or an old topic** is manual (`nats consumer edit`, `nats stream edit`): on a stream that
  several transports share, or one an operator edited, the transport cannot tell its own old pattern from a subject
  someone else needs.
- **`infection.json5`** still quotes 1768 of 1793 and a headroom of 64 in its comment; `docs/TESTS.md` has the
  numbers of this head.
- **`JetStreamInjectedNatsTransport`** is a third copy of the test doubles in `tests/unit/NatsTransportTest.php`,
  which another test file cannot load, and `filterSubjectsOf()` could read the config through `configOf()`. Both
  left as they are in this round.
