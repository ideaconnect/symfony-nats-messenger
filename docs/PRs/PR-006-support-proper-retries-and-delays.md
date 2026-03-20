# PR #6 — Support Proper Retries and Messages with Delays

- **Author:** [zlatkoverk](https://github.com/zlatkoverk)
- **Branch:** `zlatkoverk:support-delays` → `ideaconnect:main`
- **PR:** https://github.com/ideaconnect/symfony-nats-messenger/pull/6
- **Status:** Features adapted into v4.0 (not merged directly — reimplemented on the new IDCT NATS client)

## What the PR Proposed

1. **Changed `reject()` to ACK instead of NAK** — When NATS receives a NAK, it redelivers the message until it succeeds, bypassing Symfony Messenger's retry/error handling. The PR changed reject to send ACK so Messenger controls retries and error routing.

2. **Delayed / scheduled messages** — Added support for NATS scheduled messages by adding `{topic}.delayed.>` to stream subjects and enabling `allowMsgSchedules`. Used Symfony's `DelayStamp` to publish messages with NATS schedule headers.

The PR depended on a fork of `basis-company/nats` (PR basis-company/nats.php#122) which was never merged upstream.

## How We Implemented It

The PR was split into two concerns as discussed in the PR comments:

### 1. Retry handler (TERM / NAK)

Rather than changing reject from NAK to ACK, we implemented a more robust approach:

- **Default behavior changed to TERM** — `reject()` now sends TERM (not ACK) so JetStream stops redelivery, as NATS docs recommend for messages that should not be retried. This is more correct than ACK, which would silently mark a failed message as successfully processed.
- **Configurable via `retry_handler` option** — Users can set `retry_handler: nats` to restore NAK-based behavior (NATS-managed redelivery) or keep the default `retry_handler: symfony` for TERM-based behavior (Symfony-managed retries).
- **`RetryHandler` enum** — Type-safe `RetryHandler::SYMFONY` and `RetryHandler::NATS` cases.
- **Applies to both `reject()` and decode failures** — The `handleFailedDelivery()` method is used consistently for both rejected messages and messages that fail to decode.

### 2. Delayed / scheduled messages

Reimplemented on the IDCT NATS client without the dependency on the unmerged basis-company fork:

- **`scheduled_messages` option** — Boolean flag to opt in (default: `false`).
- **Publish path** — When `DelayStamp` is present and scheduled messages are enabled, the message is published to `{topic}.delayed.{uuid}` with `Nats-Schedule` (RFC 3339 timestamp) and `Nats-Schedule-Target` headers via `requestWithHeaders()`.
- **Stream setup** — When enabled, `buildDesiredSubjects()` adds `{topic}.delayed.>` and `buildManagedStreamOptions()` sets `allow_msg_schedules: true`.
- **Zero or missing delay** — Messages with `DelayStamp(0)` or no `DelayStamp` are published normally.
- **Disabled mode** — When `scheduled_messages` is `false`, any `DelayStamp` is silently ignored.

## Test Coverage

### Unit Tests

| Area | Tests |
|------|-------|
| **TERM on reject (default)** | `testRejectUsesTermByDefault` |
| **NAK on reject (nats handler)** | `testRejectUsesNakWhenRetryHandlerIsNats` |
| **TERM on failed delivery** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesBaseTermTransportPath` |
| **NAK on failed delivery** | `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseNakTransportPath` |
| **Invalid retry handler** | `testConstructorWithInvalidRetryHandlerThrowsException` |
| **Retry handler builder** | `testBuildUsesRetryHandlerFromQuery`, `testBuildWithInvalidRetryHandlerThrowsException` |
| **Decode failure handling** | `testGetDecodeFailureUsesTermWhenReplySubjectExists`, `testGetDecodeFailureDoesNotInvokeRetryHandlingWithoutReplySubject` |
| **Delayed message publish** | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders` |
| **Delay disabled** | `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally` |
| **Zero delay** | `testSendWithZeroDelayPublishesNormally` |
| **Delay with existing headers** | `testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders` |
| **Setup with scheduled messages** | `testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag` |
| **Update with scheduled messages** | `testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject` |
| **Scheduled messages builder** | `testBuildWithScheduledMessagesEnabledSetsFlag`, `testBuildWithScheduledMessagesDisabledByDefault`, `testBuildWithScheduledMessagesFromDsnQueryString` |

### Functional Tests

| Area | Scenarios |
|------|-----------|
| **NAK retry** | Message retry handled by NATS via NAK (`nats_nak.feature`) |
| **TERM failure** | Failed message routed to Symfony failure transport via TERM (`nats_term.feature`) |
| **Delayed messages** | Delayed messages are delivered after the scheduled time (`nats_delayed.feature`) |
