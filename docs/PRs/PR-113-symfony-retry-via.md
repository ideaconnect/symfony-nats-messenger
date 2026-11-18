# PR #113 - Symfony's Retries Carried Out by NATS (`retry_via: nak`)

- **Author:** [petarf-sofascore](https://github.com/ideaconnect/symfony-nats-messenger/pull/113) (Petar Fedorovsky)
- **Branch:** `feat/109-retry-delay-source-symfony` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/113, for
  [#109](https://github.com/ideaconnect/symfony-nats-messenger/issues/109)
- **Status:** Adapted, and landed on 2026-11-18 through
  [#118](https://github.com/ideaconnect/symfony-nats-messenger/pull/118) (`feat/113-retry-via-nak`), released in 5.9.0.
  That branch starts from the PR's two commits, unchanged, merges `main` in and puts the adaptation on top, one commit per
  item, and lands with a merge commit, so the contributor's commits keep their ids and #113 shows as merged.
- **Reviewed at:** commit `ea414dc` (review on 2026-11-14, decision on 2026-11-18), on `main` at `6b4789c` (5.8.1).

## What the PR Proposed

In nats mode (`retry_handler: nats`) the transport leaves out Symfony's retry copy and NAKs the failed delivery with
`nak_delay`. So Symfony's `delay`, `multiplier`, `max_delay`, jitter and the delay a `RecoverableExceptionInterface`
carries never reach NATS, and the retry count is always 0: `willRetry()` is constant and `max_retries` never ends the
retries (#109). The PR added an opt-in in two halves, one commit each:

1. **`retry_delay_source: symfony`** (`6e96be7`). Symfony's retry strategy decides, and NATS carries the retry out: the
   delivery is NAKed with the delay of the copy's `DelayStamp`, and a redelivered message gets its earlier deliveries as
   its retry count.
2. **"Dead in place"** (`ea414dc`). No failure transport: a message whose retries ran out stays in the stream, and NATS
   publishes MAX_DELIVERIES. For that the option required `max_deliver`, at `max_retries + 1`, NAKed the last delivery
   without a delay, and added `no_retry_action` (`nak` or `term`) for a delivery Symfony gives up on earlier.

It came with unit tests, a design note with measurements on nats-server 2.15.0 (`docs/explore/`), and an example.

## What Landed

The first half, moved into symfony mode as a choice of how a retry Symfony decides on reaches NATS:

```yaml
framework:
  messenger:
    transports:
      async:
        dsn: 'nats-jetstream://localhost:4222/my-stream/my-topic?retry_via=nak'
        retry_strategy: { max_retries: 3, delay: 1000, multiplier: 2, max_delay: 60000 }
```

- `retry_via: copy`, the default, is what symfony mode has always done: the retry copy is published, and the failed
  delivery is TERMed or, from Symfony Messenger 7.4.22 and 8.1.10 on, ACKed.
- `retry_via: nak` leaves the copy out, NAKs the failed delivery with Symfony's delay, and NATS redelivers the original.
  The retry count is the count the message carries plus its earlier deliveries.
- A message Symfony gives up on, an unrecoverable failure and a message that cannot be decoded are TERMed by symfony
  mode's existing code, and a failure transport gets one copy.

With the second half gone, the mode the PR added matched symfony mode in every point but this one, which is why it
moved there. Symfony mode already TERMs what Symfony gives up on, so `reject()` needs no special case and `nak_delay`
plays no part. `retry_via: nak` closes #109. Nats mode keeps its documented behaviour.

[docs/symfony_retry_via.md](../symfony_retry_via.md) is the reference, and the README section "Retrying through a NAK"
the user's guide.

## What Was Changed, and Why

| In the PR | What landed | Why |
|---|---|---|
| The name `retry_delay_source: symfony`, under `retry_handler: nats` | `retry_via: copy \| nak`, under `retry_handler: symfony` | The option changes more than the delay: the retry count, the settling and the #94 warning. `retry_via` names what changes, how Symfony's retry reaches NATS. |
| The last delivery `max_deliver` allows is NAKed without a delay, with a warning | Its retry is published as a copy, and no warning | NATS redelivers nothing after that delivery, so the NAK ended the message there: handled by nobody, and in no failure transport, since Symfony had decided to retry. That happens with `max_deliver` at or below `max_retries`, and also when a `RecoverableExceptionInterface` makes Symfony retry past `max_retries`, which no `max_deliver` can cover. Measured with `max_deliver: 2`: a recoverable failure until the fifth run is handled on the fifth as a copy, and never with the NAK (2 runs). |
| `max_deliver` required, at `max_retries + 1`, with two warnings | Optional | A message Symfony gives up on is TERMed, so nothing comes back at once, and `max_deliver` only caps deliveries nobody settles. |
| `no_retry_action: nak \| term` | Gone | It belonged to the second half. |
| "Leave `backoff` unset" in the note | The builder refuses `backoff` with `nak`, and a positive `nak_delay` | NATS adds `backoff[n] - backoff[0]` to a NAK with a delay, and `nak_delay` would never apply. Nobody uses the option yet, so refusing costs nothing. Without `nak` both stay accepted. |
| The higher of the carried retry count and the earlier deliveries | The carried count plus the earlier deliveries | A message published with a count has made those retries. Taking the higher holds the count until the deliveries catch up: published with count 1, a message ran 4 times with `max_retries: 3`, at counts 1, 1, 2 and 3. |
| A delivery nobody settled counts as a retry | Kept | |
| The #94 warning is not logged in the new mode | Kept, and its text now names `retry_via: nak` | It also says that Symfony still retries a `RecoverableExceptionInterface` with `max_retries: 0`, found on `main` during the review. |
| `docs/explore/nak-with-symfony-delay.md` and `examples/nats-retry-symfony-delays.php` (60 s) | `docs/symfony_retry_via.md`, the README section and six examples of under 15 s each | The note's server facts and measurements are kept in the reference, with their source. |

## Rejected Alternatives

- **"Dead in place".** Symfony's failure-transport listener sends a copy whenever `willRetry()` is false, and the
  transport cannot stop it. A global `framework.messenger.failure_transport` gives every transport one, so the message
  would stay in the stream and also go to the failure transport. With `no_retry_action: nak` an unrecoverable failure
  would also run the handler `max_deliver` times and send that many copies. A message that is TERMed already stays in a
  stream with limits retention. A later proposal would have to solve that duplication first.
- **`symfony_retry_strategy: true` in nats mode,** as the review of 14 November suggested. It would turn Symfony's
  strategy back on in the mode whose README and #94 warning call that a misconfiguration.
- **The retry count in nats mode too (#109 as filed).** A transport that leaves Symfony's retries on in nats mode, the
  #94 misconfiguration, would give up after `max_retries` and then send a failure-transport copy on every later
  redelivery. So nats mode stays as documented, and `retry_via: nak` is the answer to #109.
- **Applying Symfony's retry options to the consumer in nats mode.** The transport never sees the strategy:
  FrameworkBundle keeps it in a service of its own. A consumer cannot express most of it either: `backoff` does not delay
  a plain NAK, and it replaces `ack_wait` with its first step. And `max_retries: 0`, which the README asks for in nats
  mode, would become `max_deliver: 1` and turn NATS's own retries off.

## Three Fixes That Came With It

Found while the decision was checked, and fixed in the same branch:

| Fix | What was wrong |
|---|---|
| `symfony/messenger` `^7.3 \|\| ^8.0` | `composer.json` allowed 7.2. Since 5.0.0 `NatsTransport` implements `CloseableTransportInterface` (#27), which Messenger has from 7.3.0, so on 7.2 the class could not load. |
| `scheduled_messages` is refused on a server before NATS 2.12, in `setup()` and in the send of a delayed message | Such a server ignores the stream's `allow_msg_schedules` setting instead of rejecting it. `setup()` succeeded, and every delayed message, Symfony's retry copies among them, was stored on `<topic>.delayed.<uuid>` and never delivered (2.9.25, 2.10.29, 2.11.17). The README said the setup fails there. After the fix it does, and creates no stream; a delayed send is refused and stores nothing; a send without a delay is delivered; on 2.12.15 and 2.15.2 a 1.5 s delay still arrives, after 2.18 and 1.55 s. |
| CI's lowest leg lowers `symfony/messenger` too, with `scripts/check-messenger-rejects-a-retry.php` as a guard | Messenger 7.4.22, 8.1.10 and later acknowledge a message they sent for retry, and earlier releases reject it. Both legs installed a release that acknowledges (7.4.23 and 8.1.11 in run 44000217165), so the worker's `reject()` of a retried message ran only where a test calls it by hand. The first version of the decision said CI ran both, which was wrong. |

## The Commits

After the PR's `6e96be7` and `ea414dc`, and the merge of `main` (`f10534e`), which wraps the three `Future`s the PR passed
to `awaitOnConnection()` in closures, as #114 requires:

| Commit | |
|---|---|
| `8422d57` | Revert "feat: leave a message whose retries ran out in the stream (retry_delay_source=symfony)" |
| `d8715ac` | feat: retry_via: copy \| nak in symfony mode, in place of retry_delay_source (#113, #109) |
| `6201714` | fix: the nats-mode warning that Symfony's retry strategy is on names retry_via: nak (#94, #109) |
| `fb72a54` | fix: add the earlier deliveries to the retry count a message carries, with retry_via: nak |
| `c1b2514` | fix: publish the retry of the last delivery max_deliver allows as a copy, with retry_via: nak |
| `2eedcc8` | test: a Behat feature for retry_via: nak, on every server CI runs |
| `b7900fe` | build: require Symfony Messenger ^7.3, since the transport cannot load on 7.2 |
| `db60e67` | fix: refuse scheduled_messages on a NATS server before 2.12 instead of losing delayed messages |
| `4480811` | ci: run a Symfony Messenger that rejects a retried message on the lowest leg |
| `b7ae4e9` | examples: retry_via: nak under Symfony's Worker, in six examples |
| `c799e02` | examples: delayed messages, and their refusal on a server without message schedules |
| `07b0478` | examples: nats-retry.php checks the warning that Symfony's retry strategy is on |
| `fb65af8` | docs: the reference for retry_via, docs/symfony_retry_via.md |
| `690b054` | docs: describe retry_via, the refusal of scheduled_messages and the upgrade in the README |
| `d0f660b` | docs: tell agents and contributors about retry_via and the refusal of scheduled_messages |
| `7711d01` | docs: map the tests, the Behat feature and the examples of retry_via and of the refusal |
| `9a7d1d4` | docs: add retry_via, the two fixes and the CI change to the changelog |
| `366fc16` | docs: with retry_via: copy the failed delivery is TERMed or ACKed, by Messenger release |

The cut of 5.9.0 follows them on the same branch: the changelog section (`9905e4c`), the test and mutation counts
(`27b0d0c`), this record, and a dated snapshot in `docs/PROJECT_MEMORY.md`.

## What Was Measured

With Symfony's own `Worker` and its retry and failure-transport listeners, against nats-server 2.15.1, 2.15.2 and 2.10.29,
on Symfony Messenger 8.1.11 and, for the rows that depend on how the worker settles, on 7.3.0 or 7.4.21. The table is in
[docs/symfony_retry_via.md](../symfony_retry_via.md#what-was-measured), with the server facts above it: a NAK with a delay
holds a `max_ack_pending` slot, is lengthened by `backoff`, survives a restart of the server up to a second early, and on
NATS 2.9.25 a redelivery is now and then reported as a first delivery.

## Validation

On the branch before the merge, PHP 8.5.11, PHPUnit 13.4.6, client 2.29.0, Symfony Messenger 8.1.11:

- `composer test`: PHPStan at level max clean; 1143 tests, 3341 assertions. Also under
  `composer test:unit:paused` (114 pauses of 250 ms).
- Coverage 99.82% (1114/1116 statements). Every statement the branch adds is covered; the
  two that are not were there before.
- Infection: the canary's 67 mutants all escaped, and 1298 of 1317 mutants were killed
  (98.6%), with the 19 survivors `docs/TESTS.md` lists. One is new, and equivalent: a missing `DelayStamp`
  read as -1 instead of 0, for which `nakForRetry()` sends the same plain NAK.
- The whole Behat suite on NATS 2.14.2: 97 of 97 scenarios, 742 steps. Without the scheduled scenarios on NATS 2.10.29,
  as CI's oldest-server job runs it: 93 of 93 scenarios, 703 steps.
- `EXAMPLES_STRICT=1 composer examples`: 21 of 21 on NATS 2.14.2 and on 2.10.29. On the older server
  `delayed-messages.php` shows the refusal, and `retry-via-copy-or-nak.php` leaves the scheduled copy out.

On other releases of Symfony Messenger, since the worker settles a retried message in one of two ways. The first three
were run on the tree of `4480811`; `src/` has not changed since but for a comment, and the unit tests but for one case:

- The unit suite passes on 7.3.0 (one test skipped, for a method Symfony 7.4 adds), 7.4.21 and 8.1.9, whose worker
  rejects, and on 8.1.11, whose worker acknowledges. On 7.2.0 the transport cannot load, which is the reason for
  `^7.3`.
- In a PHP 8.2 container with the steps of CI's lowest leg (client 2.29.0, Symfony Messenger 7.4.0, PHPUnit 11),
  `composer test` passes, and the four Behat features that fail a message (`nats_retry_via`, `nats_nak`, `nats_term`
  and `nats_max_deliver`) pass on 7.4.21, 9 scenarios.
- The whole Behat suite passes on Symfony Messenger 8.1.9 too (97 scenarios, NATS 2.14.2). With `retry_via: copy` in
  place of `nak`, the first two scenarios of `nats_retry_via.feature` fail, on a stream that holds 3 and 2 messages.
- The eight new or changed examples pass on 7.4.21 as well, whose `PhpSerializer` throws on a payload it cannot decode.

CI on `9a7d1d4` (run 44318707618), the head before a wording fix and the release commits, which change only docs and a
comment. All three jobs passed:

- **PHP 8.2, the lowest leg:** client 2.29.0, and Symfony Messenger 7.4.0 for the unit tests and 7.4.21 in the functional
  test app, both of which `scripts/check-messenger-rejects-a-retry.php` found to reject a retried message. PHPUnit
  11.5.58: 1143 tests, coverage 99.82%. Behat 97 of 97, and 21 of 21 examples.
- **PHP 8.5:** Symfony Messenger 8.1.11, which acknowledges a retried message. PHPUnit 13.4.7: 1143 tests, also stopped
  at random moments (151 pauses), coverage 99.82%. The canary's 67 mutants escaped, and Infection killed 1298 of 1317.
  Behat 97 of 97, and 21 of 21 examples.
- **The oldest supported server:** NATS 2.10.29, on PHP 8.2 with Symfony Messenger 7.4.23. Behat 93 of 93 without the
  scheduled scenarios, and 21 of 21 examples, `delayed-messages.php` showing the refusal.

CI runs again on the head that lands, and the merge waits for it.

## Left Open

- [#117](https://github.com/ideaconnect/symfony-nats-messenger/issues/117): `nak_delay` is accepted in symfony mode,
  where it never had an effect. To warn now and refuse in 6.0.
- The composer floors are not run by CI. On Symfony Messenger 7.3.0 with FrameworkBundle 7.0.0 the unit suite passes, with
  one test skipped, but PHPStan reports two errors about the header types an older `SerializerInterface` declares.
- NATS 2.9's delivery count is documented, not worked around: `retry_via: nak` stays allowed there.
- The serializers of Symfony Messenger 8.1 and later return a message that cannot be decoded as a message of its own,
  so with them it goes through the retry strategy before it is TERMed. This transport's igbinary serializer throws, and
  the message is TERMed at once.
