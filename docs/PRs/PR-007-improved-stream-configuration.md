# PR #7 — Improved Stream Configuration

- **Author:** [coff33cat](https://github.com/coff33cat) (gogol-medien)
- **Branch:** `gogol-medien:stream-declaration-enhancements` → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/7
- **Status:** Features adapted into v4.0 (not merged directly — reimplemented on the new IDCT NATS client)

## What the PR Proposed

1. **Fix `stream_max_messages`** — The configuration parameter was not applied during stream creation; streams were always initialized with the NATS default. The PR fixed the mapping and clarified that the parameter maps to NATS `max_msgs_per_subject`.

2. **Stream storage type** — Allow setting the storage backend (`file` or `memory`) via a new `stream_storage` option. The storage type is silently preserved for existing streams since NATS does not allow changing it after creation.

3. **Multi-subject streams** — Refactored stream creation to support multiple transports sharing one NATS stream with different subjects (e.g. `orders` and `payments` on the `events` stream). On setup, new subjects are merged into the existing stream without overwriting.

4. **Improved stream update handling** — When a stream already exists, setup now reads the current JetStream configuration, merges subjects, and overlays only the managed settings.

## How We Implemented It

The PR was not merged directly because the codebase was being migrated from `basis-company/nats` to `idct/php-nats-jetstream-client`. Instead, all four features were reimplemented on the new client with improvements:

### 1. Stream max messages fix

- `NatsTransportConfigurationBuilder` validates both `stream_max_messages` (NATS `max_msgs`) and `stream_max_messages_per_subject` (NATS `max_msgs_per_subject`) as separate options.
- `NatsTransport::buildManagedStreamOptions()` maps `streamMaxMessages()` → `max_msgs` and `streamMaxMessagesPerSubject()` → `max_msgs_per_subject`.
- The PR originally conflated `stream_max_messages` with `max_msgs_per_subject`; our implementation treats them as distinct options.

### 2. Stream storage type

- `TransportOption::STREAM_STORAGE` enum case added.
- `StorageBackend` enum (`file`, `memory`) provides type-safe validation.
- `buildManagedStreamOptions()` always includes `storage` in stream options.
- On update, `buildUpdatedStreamConfiguration()` preserves the existing stream's storage backend via the server configuration overlay.

### 3. Multi-subject streams

- `buildDesiredSubjects()` returns the subjects this transport manages.
- `mergeSubjects()` combines server-side subjects with desired subjects, deduplicating.
- `normalizeSubjects()` ensures consistent array handling.

### 4. Stream update handling

- `shouldUpdateExistingStream()` detects existing streams via explicit conflict messages or stream-existence verification for ambiguous 400 responses.
- `buildUpdatedStreamConfiguration()` reads raw server config, strips the `name` field, normalizes for update, merges subjects, and overlays managed options.
- `normalizeStreamConfigurationForUpdate()` converts empty arrays to `stdClass` for JSON encoding compatibility.

## Test Coverage

### Unit Tests

| Area | Tests |
|------|-------|
| **Stream creation with all options** | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions` |
| **Max messages (create)** | `testSetupCreatesNewStreamWithMaxMessages` |
| **Max messages (update)** | `testSetupUpdatesExistingStreamWithMaxMessages` |
| **Max messages per subject (create)** | `testSetupCreatesNewStreamWithMaxMessagesPerSubject` |
| **Max messages per subject (update)** | `testSetupUpdatesExistingStreamWithMaxMessagesPerSubject` |
| **Stream update on conflict** | `testSetupUpdatesStreamWhenItAlreadyExists`, `testSetupUpdatesStreamWhenAlreadyInUseMessage`, `testSetupUpdatesStreamWhenAlreadyExistsInMessage` |
| **Subject merging** | `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, `testSetupUpdatesExistingStreamWithoutDuplicatingSubjects` |
| **Storage preservation** | `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig` |
| **Ambiguous 400 handling** | `testSetupDoesNotTreatGenericBadRequestAsExistingStream`, `testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest` |
| **Builder validation (max messages)** | `testBuildWithNegativeStreamMaxMessagesThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesThrowsException`, `testBuildWithStreamMaxMessagesFromQueryString` |
| **Builder validation (per-subject)** | `testBuildWithNegativeStreamMaxMessagesPerSubjectThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesPerSubjectThrowsException` |
| **Builder validation (max bytes)** | `testBuildWithNegativeStreamMaxBytesThrowsException` |
| **Builder validation (storage)** | `testBuildWithInvalidStreamStorageThrowsException`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues`, `testBuildMethodOptionsOverrideQueryForStreamStorageAndPerSubjectLimit` |

### Functional Tests

| Area | Scenarios |
|------|-----------|
| **Max bytes limit** | Setup stream with max bytes limit |
| **Max messages (create)** | Setup stream with max messages limit |
| **Max messages per subject (create)** | Setup stream with max messages per subject limit |
| **Max messages (update)** | Update existing stream preserves max messages limit |
| **Max messages per subject (update)** | Update existing stream preserves max messages per subject limit |
| **Memory storage** | Setup NATS stream with memory storage |
| **Per-subject limit** | Setup NATS stream with max messages per subject configuration |
| **Multi-subject streams** | Setup command merges subjects for transports sharing one stream |
| **Existing stream handling** | Setup command handles existing streams gracefully |
