# PR #139 - `stream_metadata`: Set and Remove Entries in a Stream's Metadata

- **Author:** [petarf-sofascore](https://github.com/ideaconnect/symfony-nats-messenger/pull/139) (Petar Fedorovsky, sofascore)
- **Branch:** `feat/stream-metadata` (contributor fork) → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/139; no issue is linked, so the PR's description is
  the spec.
- **Status:** the contributor's five commits unchanged, and the changes of the
  [review](https://github.com/ideaconnect/symfony-nats-messenger/pull/139#pullrequestreview-6076647845) on top of them,
  one commit per item, on the same branch.

## What the PR Proposes

A stream's `metadata` is a map of names to strings (NATS 2.10 and later) where operators and tools label streams, and the
transport had no option for it: `setup()` only sent back what was there. `stream_metadata` sets or removes entries in it:

- unset (`null`, the default), the metadata is left untouched: a new stream gets none, and on an existing stream the live
  map goes back as it is;
- on create the stream gets the configured entries; on an existing stream each configured entry is set in the live map and
  every other entry stays, as each placement option changes only its own field of the live placement;
- a null value (`team: ~`) removes an entry; a DSN has no null, so `stream_metadata[team]=` is the empty string, a value;
- names starting with `_nats.` are refused, to set and to remove: the server writes `_nats.ver`, `_nats.level` and
  `_nats.req.level` there from 2.11 on;
- anything that is not a non-empty map of non-empty names to strings, integers or null is refused when the transport is
  built, and so are entries to set over 128 KiB together, the server's `JSMaxMetadataLen`;
- NATS before 2.10 drops the field without a word, so `setup()` refuses the option there before it creates anything
  (`metadataUnsupportedByTheServer()`, next to `schedulesUnsupportedByTheServer()`).

On the way the PR fixes a bug on `main`: `setup()` failed with `invalid JSON` on a stream whose metadata had only names that
are decimal integers (`0`, `1`, ...), since PHP holds such a map as a list and the transport sent it back as a JSON list
(measured by the contributor on nats-server 2.10.29). The metadata now always goes out as a JSON object.

## What the Review Found

1. **`setup()` rewrote the live names** (blocking). `resolveUpdatedStreamMetadata()` read the live map through
   `TypeCoercion::stringMapValue()`, the lenient reading made for the option, which trims names and drops an entry whose
   name is empty. So every `setup()` of an existing stream, with the option unset too, renamed another tool's `" group "` to
   `group`, deleted an entry named `""`, and folded `team` and `"team "` into one. `main` sent them back as they came, and
   the server has no rule on names other than the size of the map.
2. `array_is_list()` refuses a list, and also a map whose names are exactly `0`, `1`, ... in that order (`{ 0: ~ }`,
   `stream_metadata[0]=a`), which PHP cannot tell from a list. Nothing said so.
3. The README did not say that a `stream_metadata` in `options` replaces the DSN's map as a whole (`$options + $query`), or
   that a configured name is trimmed.
4. This record was missing.
5. to 8. Nits: `RESERVED_METADATA_PREFIX` was public, the NATS requirement in `CLAUDE.md` did not name 2.10, the
   `examples/README.md` row did not say the example needs the cluster, and the README said the cluster runs the last four
   examples where it runs six.

## How It Was Implemented

| Commit | Change |
|--------|--------|
| `feat: stream_metadata, the entries a transport sets in its stream's metadata` | The contributor's: the option, `normalizeStreamMetadata()` in the builder, `streamMetadata()` and `streamMetadataRemovals()` on the configuration, `TypeCoercion::stringMapValue()` |
| `feat: write stream_metadata at creation, and set it in the live map of an existing stream` | The contributor's: the create path, `resolveUpdatedStreamMetadata()`, the refusal before 2.10, and the map sent as a JSON object |
| `test: a Behat feature for stream_metadata, on every server CI runs` | The contributor's: `nats_stream_metadata.feature`, five scenarios, not tagged `@delayed` |
| `examples: stream-metadata.php, on the test server and the test cluster` | The contributor's |
| `docs: describe stream_metadata in the README, the changelog and the test map` | The contributor's |
| `fix: send the live stream metadata back as it came, names untouched` | Finding 1: the merge starts from the live array as it came, and a live value that is no map reads as none; only the configured side is normalized, by the builder. Eight data sets in `metadataOnUpdate`, all failing on `349eaf0` |
| `fix: say in the refusal and the README that a map named 0, 1, ... is refused` | Finding 2: the check stays; the message and the README say it. `testBuildRefusesStreamMetadataWhoseNamesAreExactlyZeroOneInOrder`, and the names that pass in the accepting data sets |
| `docs: the options' stream_metadata replaces the DSN's map, and names are trimmed` | Finding 3 |
| `refactor: make RESERVED_METADATA_PREFIX private` | Finding 5 |
| `docs: name NATS 2.10 for stream_metadata in CLAUDE.md` | Finding 6 |
| `docs: say that stream-metadata.php needs the cluster` | Finding 7 |
| `docs: the cluster runs the last six examples, not four` | Finding 8 |
| `docs: PR-139, what the PR proposed, how it is implemented and its tests` | Finding 4: this record |

## Behavioural Contract

- Unset, the option changes nothing: the live map goes back as it came, names and values untouched, as an object.
- A configured entry sets the live entry of exactly its (trimmed) name; a null value removes it; every other live entry
  stays, the server's own `_nats.` entries and names another tool wrote with space around them or empty included.
- When the last entry goes, the field is left out, which is how the server is told to clear the map.
- A `stream_metadata` in `options` replaces the DSN's map as a whole; a DSN cannot remove an entry.
- Refused when the transport is built: no map, an empty map, a map whose names are exactly `0`, `1`, ... in that order, an
  empty name, a value that is no string, integer or null, a name set and removed or named twice once trimmed, a `_nats.`
  name, and entries to set over 128 KiB together.
- Refused at `setup()` on NATS before 2.10, before anything is created.

## Tests

| Area | Tests |
|------|-------|
| Builder | `testBuildAcceptsStreamMetadata`, `testBuildAcceptsStreamMetadataEntriesToRemove`, `testBuildWithInvalidStreamMetadataThrowsException`, `testBuildRefusesStreamMetadataWhoseNamesAreExactlyZeroOneInOrder`, `testBuildRefusesAStreamMetadataNameNatsKeepsForItself`, `testBuildRefusesToRemoveAStreamMetadataNameNatsKeepsForItself`, `testBuildRefusesStreamMetadataLargerThanTheServerAllows` |
| Accessors and coercion | `testStreamMetadataAccessorReadsTheMapAndTreatsAnythingElseAsUnset`, `testStreamMetadataRemovalsAccessorReadsTheNamesGivenNull`, `testStringMapValue` |
| Create and update | `testSetupPassesStreamMetadataOnCreate`, `testSetupUpdateSetsTheConfiguredEntriesInTheLiveMetadata` (23 data sets; the eight of finding 1 fail on `349eaf0`) |
| Server version | `testSetupRefusesStreamMetadataOnAServerWithoutIt`, `testSetupRefusesAStreamMetadataRemovalOnAServerWithoutStreamMetadata`, `testSetupGoesOnWhenTheServerMayHoldStreamMetadataOrNoneIsSet`, `testSetupConnectsToReadTheVersionBeforeItRefusesStreamMetadata` |
| Functional | `nats_stream_metadata.feature`, five scenarios; `examples/stream-metadata.php`, on the test server and a stream with three replicas on the test cluster |

## Validation

Measured on the branch with the review's changes, PHP 8.5.9, PHPUnit 13.4.8, client 2.33.0:

- `composer test` with Symfony Messenger 8.1.11: PHPStan level max no errors; 1478 unit tests, 4139 assertions, OK.
  With 8.1.12: 4 failures, the four tests of #140, which fail on `main` too.
- `composer test:unit:paused` (8.1.11): OK, 1478 tests.
- `composer test:unit` with PCOV and `composer coverage:check`: 99.80 % (1511 of 1514); the three uncovered statements are
  older than this PR.
- `composer test:mutation:canary`: 92 mutants that change nothing, all 92 escaped. Infection on the lines the branch
  changes (`--git-diff-lines` against `main`): 98 mutants, 98 killed, 100 % mutation code coverage and covered MSI;
  `composer test:mutation:check-log` clean.
- `nats_stream_metadata.feature` (5 scenarios, 33 steps) and `examples/stream-metadata.php` passed on nats-server 2.15.3
  and on 2.10.29 (`nats:2.10-alpine`), the cluster included, with the functional app on client 2.33.0 and Messenger 8.1.12.
- Finding 1 on a real server: a stream created with the entries `" group "`, `""`, `team` and `"team "` keeps all four
  through a `setup()` without the option and through one that sets `shard`, on 2.15.3 and on 2.10.29. At `349eaf0`
  (measured on 2.15.3) the first `setup()` left two entries, `{"group":"odds","team":"ops"}`.
- Not run: the whole Behat suite, the whole mutation run, the benchmark, and NATS 2.9 (the refusal there is covered by unit
  tests and by the example's branch for it, which CI does not reach).
