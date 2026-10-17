# PR #45 (placement half) - `stream_placement_cluster` and `stream_placement_tags`

- **Author:** Petar Fedorovsky (sofascore)
- **Branch:** `feat/80-stream-placement` → `ideaconnect:main`, superseding the placement half of
  https://github.com/ideaconnect/symfony-nats-messenger/pull/45
- **Issue:** https://github.com/ideaconnect/symfony-nats-messenger/issues/80 (the review of the PR, four comments
  and the implementation plan this branch follows)
- **Status:** the placement half of #45 adapted and completed; the reconnect half (`reconnect`,
  `max_reconnect_attempts`) is tracked in https://github.com/ideaconnect/symfony-nats-messenger/issues/81 and is
  not part of this branch.

## What the PR Proposed

Two transport options that write JetStream's `placement` block: `stream_placement_cluster` (the `placement.cluster`
pin) and `stream_placement_tags` (the `placement.tags` list, as a YAML list, `option[]=a&option[]=b` or a
comma-separated DSN value through the new `TypeCoercion::stringListValue()`). Both default to `null`, which leaves
the stream's placement alone; on the update path the live block is echoed back, because a STREAM.UPDATE that omits
`placement` clears it. The PR came with builder validation, configuration accessors, unit tests for create, update
and preservation, three Behat scenarios on a standalone server, and README, CHANGELOG and TESTS.md text. Its third
commit mixed reconnect and placement README text; only the placement hunk is taken.

## What Was Wrong

The review in #80, measured on nats-server 2.9, 2.10 and 2.14 standalone and on a three-node 2.14 cluster, and
checked in the nats-server source (2.14.2 and 2.15.0):

1. **One option wiped the other half of a live placement.** `buildUpdatedStreamConfiguration()` merged the block
   built from the options with a shallow `array_merge`, so for a stream pinned in NATS to
   `{"cluster": "east", "tags": ["hdd"]}`, `stream_placement_tags=ssd` wrote `{"tags": ["ssd"]}` and the pin was gone,
   and `stream_placement_cluster=west` wrote `{"cluster": "west"}` and the tags were gone. The server accepted both
   and moved the replicas without the dropped constraint.
2. **A placement change together with `stream_replicas` took an `auto_setup` transport down.** nats-server reads any
   difference in the placement block as a move request and refuses a move combined with a scale
   (`can not move and scale a stream in a single update`, err_code 10123). The done flag of `auto_setup` latches only
   after `setup()` succeeds, so every `send()` and `get()` repeated the rejected update and threw. A tag list equal
   to the live one in another order counted as a move too, so it was rejected with a replica change as well.
3. **The Behat steps were doc-comment annotations**, which Behat 4 (#79) does not read; under the strict
   configuration the three scenarios failed as undefined.
4. **Smaller items:** the preservation scenario proved nothing about the update (the assertion held when the update
   never ran), the cluster name was trimmed twice with different rules, and the `TransportOption` docblock groups did
   not list the new cases.

Two recommendations made along the way were dropped before implementation, for reasons recorded in #80: filling
`placement.cluster` in from the live stream's cluster (a pin nobody asked for that the options could never clear),
and comparing tags case-insensitively (the server matches tags case-insensitively, but it switches its `unique_tag`
check off through a case-sensitive prefix test on the placement tag, so folding case could silently keep a bypass the
operator removed; measured on 2.14.7 with `!zone:unused` accepted and `!ZONE:unused` rejected).

## How It Was Adapted

One commit per item, on top of the PR's placement commit cherry-picked onto `main` after 5.5.0:

| Commit | Change |
|--------|--------|
| `feat: add stream_placement_cluster and stream_placement_tags options` | The PR's placement commit, with the reconnect lines next to it left out of the conflicting files and the three Behat steps as attributes |
| `docs: describe placement moves and exclusion tags` | The placement hunk of the PR's README commit, as the contributor wrote it |
| `fix: merge the configured placement into the live block field by field` | `resolveUpdatedStreamPlacement()` starts from the live block and lets each option replace its own field; `sameTagSet()` keeps the live tag array as the server stores it when the configured tags name the same set, compared exactly; never emits an empty `tags` or `cluster` |
| `refactor: share the cluster name normalization between builder and accessor` | `TypeCoercion::nonBlankStringValue()`, used by the builder's validation and the accessor, which keeps coercing because the configuration constructor is public |
| `docs: list the placement options in the TransportOption docblock groups` | A `Stream Placement` group in the enum's docblock |
| `fix: explain how to stage a placement change that comes with a replica change` | A `JetStreamException` arm in the outer catch chain of `setup()`; for err_code 10123 the message adds the staging steps and the `auto_setup` consequence; the `RuntimeException` type stays and the client exception is its direct previous. The update is not split: with `auto_setup` a half-applied `setup()` would latch the done flag |
| `test: prove the placement merge and the update on a real server` | The max-age assertion in the preservation scenario; three scenarios for the merge against the standalone server, which stores a cluster name without acting on it |
| `test: cover what the placement changes left to the mutation run` | A test for the generic wrap of `setup()`, which JetStream rejections no longer reach; two more merge cases; three equivalent branches removed (`array_values()` and a redundant `array_unique()` in the tag comparison, a null check before `stringListValue()`, a scalar check the coercion makes itself) |
| `docs: say how a tags-only placement resolves in a super-cluster and show the two stages` | README: the super-cluster rule, the two-stage example, the note next to `auto_setup`; this document |

## Behavioural Contract

- Unset options leave the live placement as it is, on create (server default) and on update (echoed back).
- On update each option replaces only its own field; the other field survives. Neither option can clear a field;
  that is done in NATS directly.
- Configured tags that name the live set, order and repeated entries aside, keep the live array byte for byte, so no
  move is requested and a replica change in the same run is accepted. Tags are compared case-sensitively; a case-only
  change is sent as a change and the stream is stable from the next `setup()` on.
- A placement change combined with a `stream_replicas` change on an existing stream fails with the server's wording,
  the staging steps and the `auto_setup` consequence; other rejections keep the plain wording.
- No cluster pin is made up from the live stream. A tags-only placement in a super-cluster is resolved in the
  cluster the setup client connects to first; the README says to set `stream_placement_cluster` to keep a stream
  where it is.

## Tests

| Area | Tests |
|------|-------|
| Merge on update | `testSetupUpdateMergesConfiguredPlacementIntoTheLivePlacement`, 16 cases; seven fail on the PR's code (tags only, cluster only, a cluster equal to the live one, reordered tags alone, with a cluster change and with a replica change, a repeated live tag) |
| Move-and-scale rejection | `testSetupExplainsHowToStageAMoveAndScaleRejection` (fails on the PR's code), `testSetupKeepsThePlainWordingForOtherUpdateRejections`, `testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEverySend`, `testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEveryGet` |
| Shared normalization | `testNonBlankStringValue` (11 inputs), the accessor test's cluster of a rejected type (fails on the PR's code, which read `true` as the cluster `"1"`) |
| Create path, accessors, builder, coercion | The PR's own tests, unchanged |
| Functional | `nats_stream_placement.feature`, six scenarios: create with tags, update with tags, preservation when unset (now with the max-age assertion), tags keep the cluster pin, a cluster keeps the tags, reordered tags keep the stored list |

## Validation

- `composer test`: PHPStan level max clean, 623 unit tests, 1,711 assertions (PHP 8.5.11, PHPUnit 13.4.3, client 2.27.0).
- `composer test:unit` + `composer coverage:check`: 99.77 % statement coverage (860 of 862).
- `composer test:functional` against the suite's Docker servers (plain, TLS, mTLS and the one that drops idle
  clients, `nats:alpine`): 58 scenarios, 452 steps, all passed in 7 min 20 s under the strict Behat 4 configuration,
  with the functional app on client 2.27.0 and Behat 4.0.0.
- `composer test:mutation:canary` passed (47 no-op mutants, all escaped as they must). Infection on the four changed
  source files: 938 mutants, 906 killed, 100 % mutation coverage, covered MSI 96 %; the 32 that escape are the
  survivors `docs/TESTS.md` documents for these files. The whole-suite run is CI's PHP 8.5 job.
- Not run for the finished code: a NATS cluster. The cluster measurements behind the findings (the dropped half,
  the reordered-tags move, err_code 10123 with a replica change, the case-sensitive `unique_tag` bypass) were made
  during the review on three-node 2.14 and 2.14.7 clusters and are recorded in #80; the server behaviour the merge
  relies on was checked in the nats-server source (2.8.4, 2.14.2, 2.15.0).
