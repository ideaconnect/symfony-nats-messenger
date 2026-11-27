# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`get()` now declares Messenger's fetch size** (#150). Symfony Messenger 8.1 added a `$fetchSize` hint to
  `ReceiverInterface::get()`, which the Worker passes on every pull (`messenger:consume --fetch-size`, and
  `--concurrency` on Messenger 8.2); without a matching parameter, Symfony's `DebugClassLoader` (the dev
  environment) reported a deprecation when it loaded the class. `get()` declares the parameter as a comment,
  with a matching `@param` line, as `ReceiverInterface` itself declares it until Symfony 9 - the deprecation is
  gone, and `get()` stays compatible with a subclass that overrides it. The fetch size is still not used: a
  pull asks for `batching()`'s own count, since a pull that waits for a larger one holds the connection until
  `max_batch_timeout` runs out, and little is lost with one message per pull, as the Worker pulls again while
  its strategy has a free slot. Nothing changes for a running deployment; whoever wants more messages per call
  sets `batching`.
- **In nats mode a failure of several handlers is TERMed only when every handler failed for good** (#125), by the rule
  Symfony's retry listener goes by: a `HandlerFailedException` is unrecoverable only when every exception it wraps
  directly is an `UnrecoverableExceptionInterface`. 5.10.0 and 5.10.1 read the failure from Symfony's
  `ErrorDetailsStamp`, which names the first of them only, so when Symfony did not request a retry the handler that ran
  first decided for all of them whether the transport sent TERM or NAK, in the order the container registered the
  handlers, which nobody chooses on purpose; earlier releases NAKed ordinary and unrecoverable handler failures alike.
  With `max_retries: 0`, as nats mode asks for, an unrecoverable failure first TERMed the message, and the other
  handler's failure, which a redelivery could cure, was never tried again; an ordinary failure first NAKed it, and NATS
  ran both handlers again up to `max_deliver`. A mix of an unrecoverable failure and an ordinary one is now NAKed in
  either order, and the delivery where the unrecoverable failure is the only one left is TERMed: JetStream publishes
  `MSG_NAKED`, then `MSG_TERMINATED`, for the message. Failures that are all unrecoverable are TERMed at once, as
  before, and a message with one handler is settled as before. A failure Symfony retried is still NAKed in place of its
  retry copy, through `ack()` or `reject()`. `isAFailureNatsModeCannotRetry()`.
- **`setup()` keeps the metadata and the description of a durable consumer that exists** (#145). It sent the consumer's
  configuration built from the options alone, and nats-server takes that request as an update that replaces the whole
  stored configuration, so every `setup()` (`messenger:setup-transports`, and with `auto_setup` the first send or get
  of a process) cleared a metadata entry or a description another tool had written onto the consumer, such as
  `nats consumer edit` or an operator's label: measured on nats-server 2.10.29 and 2.15.3, on one server and on a
  cluster of three with three replicas. The transport has no option for either field, so it now sends back what the
  live consumer has, read from the lookup it already makes for the replay policy: the metadata whole, names and values
  as the server reported them, the server's own `_nats.` entries included, and the description when there is one. A
  map whose names are `0`, `1`, ... in order goes as a JSON object, not the list PHP's `json_encode()` makes of it,
  which the server refuses (`invalid JSON`, on both servers); a consumer with no metadata, or an empty map, sends no
  field. A new consumer gets neither field, as before. An entry written between that lookup and the update is still
  lost: the consumer API has no conditional update. `keepTheExistingConsumerLabels()`.

### Added

- **`AddUnrecoverableFailureStampListener`**, a `WorkerMessageFailedEvent` subscriber the bundle registers
  (`kernel.event_subscriber`), for the fix above. For a `HandlerFailedException` it adds an `UnrecoverableFailureStamp`
  (not sendable) saying whether every exception it wraps directly is unrecoverable, and the worker passes that envelope
  to `reject()`, where the transport reads the stamp before the `ErrorDetailsStamp`, `false` included: a stamp for
  `true` alone would leave the mix whose first exception is unrecoverable to the `ErrorDetailsStamp`, which TERMs it.
  Without the stamp, in a worker that does not register the listener, the transport goes by the first exception, as in
  5.10.0 and 5.10.1, when Symfony requested no retry (with `max_retries: 0`, as nats mode asks for); a delivery Symfony
  sent for retry is NAKed in place of the copy, whatever its first exception. The listener classifies only a delivery a
  transport in nats mode received, since only that mode reads the stamp: `get()` marks it, in nats mode, with a
  **`ReceivedInNatsModeStamp`** (not sendable) holding its delivery token, and the listener requires that token to be
  the one in the envelope's last `TransportMessageIdStamp`. The envelopes of other receivers, such as an in-memory
  transport, stay as they are, and so does a copy of a nats-mode delivery that another transport stores, when that
  transport gives the copy an id of its own, as an in-memory failure transport does, or serializes it, which drops the
  marker. Symfony leaves that id to the transport, so a custom transport that stores the envelope as it is and returns
  it without an id of its own keeps the marker and the NATS delivery's id, and a failure of that copy in another worker
  is classified too (the NATS delivery was settled already, and only nats mode reads the stamp); such a transport drops
  the non-sendable stamps when it takes an envelope, or gives it an id of its own (README). It runs at priority -101,
  after Symfony's retry listener (100) and failure-transport listener (-100) have taken their copies: at priority 0 an
  in-memory failure transport without a serializer, which stores the envelope as it is given, kept the stamp on its
  copy. An application without the bundle registers it in its services.yaml with the `kernel.event_subscriber` tag,
  and a worker built by hand adds it to its event dispatcher with `addSubscriber()` (README, "Retry Handler Behavior").

### Testing & CI

- **Two handlers through Symfony's Worker** with its error-details and retry listeners and `max_retries: 0`:
  `testNatsModeSettlesAFailureOfTwoHandlersByEveryExceptionThroughTheWorker` NAKs the mix in both orders and TERMs two
  unrecoverable failures, and without the listener still TERMs the mix whose first exception is unrecoverable. The mix
  with the unrecoverable failure first fails on 5.10.1. `testTheCopiesSymfonyTakesOfAFailureDoNotCarryTheListenersStamp`
  checks that neither the failure copy nor the retry copy, both in an `InMemoryTransport` without a serializer, carries
  the stamp, while the envelope the worker settles carries `false`, and tells the two copies apart by the
  `SentToFailureTransportStamp` only the failure copy carries. `testNatsModeTermsAnUnrecoverableFailureAndNaksTheRest`
  gains the stamp's rows. `testGetMarksADeliveryInNatsModeWithItsToken` checks that `get()` adds the marker in nats
  mode only, and `testTheListenerLeavesAnInMemoryFailureCopyOfANatsModeDeliveryAloneWhereItFailsAgain` that a copy
  keeping it in an in-memory failure transport gets no stamp there.
  `testACopyAnEnvelopePreservingTransportStoresIsClassifiedUnlessItDropsTheMarkerOrGetsAnIdOfItsOwn` runs a custom
  failure transport that stores the copy as it is, where its failure in another worker is classified (`true`), without
  its non-sendable stamps, or with an id of its own (no stamp in either), the NATS delivery TERMed in each row. The
  listener has tests of its own (`AddUnrecoverableFailureStampListenerTest`, among them a worker whose receiver is an
  `InMemoryTransport`, and envelopes without the marker or with another delivery's), and
  `theListenerOfHandlerFailuresIsRegisteredOnceAfterSymfonysCopies` finds it on the compiled event dispatcher once, at
  priority -101, with the bundle, with the README's services.yaml entry, and with both.
- **The Behat scenarios of #125 and row 22 of #123** in `nats_nak.feature`: one handler fails for good, the other once,
  in both orders, with `max_retries` 0 and 3, on a real server: `MSG_NAKED`, then `MSG_TERMINATED` for stream sequence
  1, and each handler runs twice; two handlers failing for good are TERMed at the first delivery. The rows with
  `max_retries: 3` expect the transport's warning that Symfony's retry strategy is on once, and those with 0 none, so
  they fail if Symfony's retry stops taking place. The functional app gains `TwoHandlersMessage`, its two handlers and
  `app:send-two-handlers-message`. The row with the unrecoverable handler first and `max_retries: 0` fails on 5.10.1,
  with a single `MSG_TERMINATED`. Run on nats-server 2.15.2 with client 2.30.1 and Symfony Messenger 8.1.11 and 7.4.21.
- **The example `nats-retry-several-handlers.php`** shows the same through Symfony's Worker, the four runs and the two
  handlers failing for good, with what Symfony decided for each delivery (`willRetry()` after its retry listener:
  `[false, false]` with `max_retries: 0`, `[true, false]` with 3); on 5.10.1 it stops at the first run, where the
  message was TERMed after one run of each handler.
- **The four tests that compared every record a `RecordingLogger` recorded now compare only the transport's own
  warnings** (`RecordingLogger::messagesAt('warning')`), not every record (#140): `testNatsModeWarnsOnceThatSymfonysRetryStrategyIsOn`,
  `testNatsModeWithoutSymfonysRetriesLogsNoWarning`, `testNakRetryFollowsSymfonysRetryStrategyThroughTheWorker`,
  `testNakRetryRunsSymfonysStrategyToItsEndPastMaxDeliver`. From Symfony Messenger 7.4.24 and 8.1.12 on, the Worker
  calls `close()` when it stops, which the client logs at `info` level, and these four tests failed on that extra
  record. Each still fails when the transport itself logs one warning too many (shown in PR #142's review).
- **`testRunsAfterSymfonysListenersHaveTakenTheirCopies` reads the priorities from an `EventDispatcher`** (#149),
  never from Symfony's `getSubscribedEvents()` arrays by position: EventDispatcher 8.2 lets a listener name those
  keys instead, and Messenger 8.2.0-RC1 does so for `AddErrorDetailsStampListener` and
  `SendFailedMessageForRetryListener`, which left the old `$subscribed[1]` lookup reading past the end of the array.
  Run with `symfony/messenger` 7.4.0, 8.1.12 and 8.2.0-RC1, `symfony/event-dispatcher` 7.4.17, 8.1.5 and 8.2.0-BETA1,
  and PHPUnit 11.5.58 and 13.4.8.
- **One new unit test pins what a `DispatchOnFailureStamp` (new in Symfony Messenger 8.2) does in nats mode** (#154):
  `testDispatchOnFailureStampIsDispatchedOnlyWhenSymfonyGivesUpThroughTheWorker` (data provider: through Symfony's
  Worker, nats mode with `max_retries: 0` dispatches the stamp's failure message at every failed delivery, with
  retries left on only an unrecoverable failure dispatches it, and `retry_via: nak` dispatches it once, at the
  delivery Symfony gives up on; no row leaves a copy in the failure transport). Skipped before Symfony Messenger
  8.2; run against `symfony/messenger` 8.2.0-RC1.
- **Two new unit tests pin what Symfony Messenger 8.1.12 changed in its `Worker`** (#140):
  `testTheWorkerReadingTheWholePullFirstLeavesNoMessageHandledWhenTheSecondsDecodeFails` (a pull of two messages,
  the second's decode throwing a plain exception: before 8.1.12 the first message is handled and acknowledged
  before the exception leaves `Worker::run()`; from 8.1.12 on, which reads the whole pull before it handles the
  first message, neither message is handled and nothing is settled; the exception also calls `close()` once from
  Messenger 7.4.24 and 8.1.12 on, counted the same way as the next test) and
  `testTheWorkerClosesTheTransportWhenItStopsOnTheMessengerReleasesThatDoSo` (one handled message, a stop by
  `StopWorkerOnMessageLimitListener(1)`: `close()` is called once from Messenger 7.4.24 and 8.1.12 on, and never
  before, counted by overriding `close()` in an anonymous subclass, not read from the client's log line; the
  client's own `info` line `NATS connection Closed` is checked beside it, with a `RecordingLogger`, present where
  `close()` is called and absent where it is not). Both read the installed release through two new private
  helpers, `workerReadsTheWholePullFirst()` and `workerClosesTransportsWhenItStops()`, next to the existing
  `workerAcknowledgesAMessageItSentForRetry()`. Run against Messenger 8.1.12 (the default), and against 8.1.11 and
  7.4.24 in a separate `composer update --with symfony/messenger:<version>` copy of the tree; also checked against
  7.4.0.

### Documentation

- **README, "Losing the Connection"**: who calls `close()` (Symfony's Worker, each time it stops, at a normal stop
  and when an exception ends it, from Messenger 7.4.24 and 8.1.12 on; nothing in Symfony called it before those
  releases), and what an operator sees (the client's `info` line `NATS connection Closed`) (#140).
- **README, "Any other exception of the serializer"**: from Messenger 8.1.12 on, with `batching` above 1, the
  messages of the same pull decoded before the failing one are not handled either, and are left for redelivery the
  same way (#140).
- **README, "Batching Explained"**: one sentence that from Messenger 8.1.12 on the Worker reads the whole pull
  before it handles the first message, linking to the paragraph above (#140).
- **`docs/TESTS.md` and `docs/PROJECT_MEMORY.md`** updated for the tests and the Worker behavior above (#140).
- **README, "Retry Handler Behavior"**: one paragraph on what a `DispatchOnFailureStamp` (Symfony Messenger 8.2)
  does in nats mode and with `retry_via: nak`, and the sentence in `docs/symfony_retry_via.md` that the stamp's
  failure message is dispatched on each run Symfony gives up on (#154).
- **README, "Upgrading from 5.10.1"**: a section naming the unreleased changes an operator can notice (nats mode
  with several handlers, #125; `setup()` keeping a consumer's metadata and description, #145), and the sentence
  in `docs/PROJECT_MEMORY.md` that the "Upgrading from" sections get such a bullet on the branch that makes the
  change (#147).

## [5.10.1] - 2026-11-21

A **patch** release with two fixes found after 5.10.0, and the client release the second needs. A warning or notice
of the application's own code while a message is unserialized is the application's again, not a decode failure that
TERMed the message (#122). With `reconnect: true` and a user allowed to pull but not to ask for consumer info, a pull's
503 from a server going away is an empty batch within `request_timeout` again, while the client goes on reconnecting:
5.9.1 and 5.10.0 held `get()` for the whole reconnect, and a worker in it did not react to `messenger:stop-workers` or a
SIGTERM (#131). The transport now requires `idct/php-nats-jetstream-client` `^2.30.1`.

### Changed

- **`idct/php-nats-jetstream-client` `^2.30.1`** (#131), in `composer.json` and in the functional test app. With
  `reconnect: true`, a pull's 503 from a server going away is an empty batch within `request_timeout` again for a user
  allowed to pull but not to ask for consumer info, while the client goes on reconnecting. After the 503 the transport
  asks the client why (`pullMetAServerGoingAway()`, #106); the server refuses that user's question at once, and the
  client then watches the connection for the rest of the question's budget. Client 2.30.0 awaited a read that ran the
  reconnect, or the lame duck failover to another server, itself, and so returned only once that was over: `get()`
  stayed in the diagnosis for as long as the server was away, and a worker in it did not react to
  `messenger:stop-workers` or a SIGTERM until then, so a deploy or a scale-down during an outage had to kill it. 5.9.1
  and 5.10.0 had it, and 5.8.1 and 5.9.0 too once their client was updated to 2.30.0. Client 2.30.1
  (ideaconnect/php-nats-jetstream-client#257, with #256 in the same loop) looks at the connection every 20 ms whatever
  that read does. The `pullMetAServerGoingAway()` docblock no longer says that the server leaves such a user's question
  unanswered.

### Fixed

- **A warning or notice the application's own code raises while a message is unserialized no longer makes the message
  one that cannot be decoded** (#122). 5.10.0 made every warning or notice raised during `deserialize()` a
  `MessageDecodingFailedException`, also one of the code that unserializing a message runs: the `__wakeup()` or
  `__unserialize()` of the message and of the objects in it, and the autoloader that loads their classes, even one
  that code suppressed with `@`, for which PHP calls the error handler all the same. Such a message, which 5.9.x
  decoded, was TERMed without its handler running, and in symfony mode one copy went to the failure transport when
  there was one (measured with nats-server 2.14.2 and Symfony Messenger 8.1.11). The transport's serializers
  (`AbstractEnveloperSerializer`, `IgbinarySerializer`) now read from the call stack which code raised it. The
  decoder's own warnings are still the decoding failure, whatever the application's error handler does with warnings:
  one the outermost `igbinary_unserialize()` or `unserialize()` call raises itself (igbinary's about a payload that is
  not igbinary, cut short or with bytes after its data, and PHP's about bytes after the end of a payload, from PHP 8.3,
  whose value it still returns), and one raised by code running on the serializer object, such as its
  `deserialize()`, inherited or not. Neither the file nor the lines of `deserialize()` tell them apart: an override
  that calls `parent::deserialize()` or a codec helper calls the parser elsewhere, and a closure declared in
  `deserialize()` can run inside the parser as an autoloader. A warning or notice of code that runs inside the parser
  call, or of a codec helper of another object, goes to the error handler there was, as up to 5.9.x, or to PHP's own
  when there was none or it returns false; so does every deprecation. PHP gives that handler back without the error
  levels it was set for, so it is called for every level. An exception it throws propagates as it is and leaves the
  message for redelivery, as any other exception of a serializer does: Symfony's error handler throws a warning that
  is not suppressed as an `ErrorException` in debug mode. `deserializeOrFail()`, `raisedByTheDecoder()`.

### Testing & CI

- **The required regression of #131, with the real client:** `GracefulStopPullTest` refuses the consumer-info question
  as nats-server refuses such a user, then closes the connection or announces lame duck mode naming another server on
  the client's next read, with the dial of the next server held and `request_timeout` 30 s: `get()` returns `[]` while
  the client is still `Connecting`, and once the dial goes on the next pull delivers a message that the transport
  acknowledges. Both fail with client 2.30.0, where `get()` returned only after the scripted connection let the held
  dial go 10 s later. The control, a refused question on a connection that stays, reaches its deadline and the 503
  propagates, with and without `reconnect`. The scripted server gains `$refuseConsumerInfo` and `$afterTheRefusal`.

## [5.10.0] - 2026-11-21

A **minor** release that makes the two retry modes plain, from the review of #119, whose `give_up_via` is not taken. In
symfony mode Symfony decides and its failure transport keeps what it gives up on; in nats mode NATS decides, and what
can never succeed is TERMed: a failure Symfony marks as unrecoverable, and a message that cannot be decoded. A message
that cannot be decoded is handled as Symfony's own transports handle it, and on Symfony Messenger 8.1 it goes to the
worker as the decoding failure. ⚠️ An option the transport does not know is refused when the transport is built, and
only a `MessageDecodingFailedException` of the serializer counts as a decode failure. New warnings say when Symfony's
retry is published without its delay, and when NATS deletes from a stream what `max_deliver` stops. The public PHP API
gains `TransportOption::isCredential()` and `TransportOption::credentials()`.

### Added

- **A warning when Symfony's retry is published without its delay**, once per worker: every retry with `retry_via:
  copy` and no `scheduled_messages`, and with `retry_via: nak` a retry that goes out as a copy, which the warning
  tells apart: the retry of the last delivery `max_deliver` allows, or one that carries no delivery token. Up to 5.9.x
  the delays were dropped without a word. `warnIfARetryLosesItsDelay()`.
- **A warning at `setup()` in nats mode with `max_deliver`** when the stream is one where NATS deletes a message that
  reached `max_deliver`: interest retention on a clustered server, interest on a standalone server before 2.12.9 or on
  2.14.0, work-queue with replicas before 2.12.5. Nats mode keeps its behaviour. Not from the setup `auto_setup` runs
  for a send: a process that only sends, as each PHP-FPM request does, would log it every time.
  `warnIfNatsModeLosesWhatMaxDeliverStops()`.
- **The example `nats-retry-on-a-cluster.php`** shows on the test cluster the warning, NATS deleting a message stopped
  at `max_deliver` from an interest stream with three replicas, and a work-queue stream keeping it.

### Changed

- **⚠️ An option the transport does not know is refused**, in the options and in the DSN query, with an
  `InvalidArgumentException` that names the closest option when one is near: `Unknown option 'max_delivers' in the DSN
  query of the NATS transport. Did you mean 'max_deliver'?`. Up to 5.9.x such an option was ignored without a word,
  so a mistyped name (`max_delivers`, `retry_handeler`, `RETRY_HANDLER`) left the default in place and the setup it
  was meant to change did not happen. A configuration with such a name now fails when the transport is built: correct
  or remove the name. `transport_name`, which FrameworkBundle adds, is accepted. The name is not repeated when it is
  not plain or when it follows a credential in the DSN query, where an `&` that was not encoded as `%26` in the
  credential makes the rest of it a name. `assertKnownOptions()`.
- **Nats mode TERMs a failure Symfony marks as unrecoverable** (`UnrecoverableExceptionInterface`, read from Symfony's
  `ErrorDetailsStamp`), instead of NAKing it. It can never succeed, and NATS ran the handler again on every delivery
  `max_deliver` allowed: 3 runs with `max_deliver: 3`, now 1. Ordinary failures are still NAKed, for NATS to redeliver.
  A failure Symfony retried is NAKed however the worker settles it, as with Symfony's retries left on a
  `HandlerFailedException` whose first exception is unrecoverable and another is not. `isAFailureNatsModeCannotRetry()`.
- **A message that cannot be decoded is handled as Symfony's own transports handle it.** From Symfony Messenger 8.1
  on, a `MessageDecodingFailedException` the serializer throws, as this transport's igbinary serializer does, now
  gives a `MessageDecodingFailedException` message, as Messenger 8.1's `ReceiverInterface` asks and its Doctrine,
  Redis and AMQP receivers do: the worker fails it, and Symfony's retry strategy and failure transport deal with it in
  symfony mode (8.1.11 and later fail it once, as unrecoverable), while nats mode TERMs it. Up to 5.9.x `get()` TERMed
  it and threw the decode error on 8.1 as well, which stopped the worker, once per such message. Before 8.1 that stays
  as it was, and as Symfony's own transports do. `get()`, `messengerPassesOnDecodeFailures()`, `decodingFailure()`.
- **⚠️ Only a `MessageDecodingFailedException` is a decode failure,** as for Symfony's own transports. Any other
  exception of the serializer propagates, and the message is left for redelivery after `ack_wait`: a serializer whose
  store or service failed for a moment may decode it then. Up to 5.9.x the transport took any exception for a decode
  failure and TERMed the message. The transport's serializers (`AbstractEnveloperSerializer`, `IgbinarySerializer`)
  now turn every way they fail into a `MessageDecodingFailedException`, as Symfony's `PhpSerializer` does: an
  exception of `deserialize()`, and a warning or notice it raises, which Symfony's error handler throws as an
  `ErrorException` in debug mode. igbinary warns about a payload that is not igbinary, so in debug mode such a
  message stopped the worker without being settled. A custom serializer should throw a
  `MessageDecodingFailedException` for a payload it cannot decode.

### Documentation

- **The README's "Retry Handler Behavior" states the two modes plainly:** in symfony mode Symfony decides and its
  failure transport keeps what it gives up on; in nats mode NATS decides, a failure transport is not for that mode,
  and what can never succeed is TERMed. The comparison of `copy`, `nak` and nats mode gains rows for an unrecoverable
  failure, a message that cannot be decoded and the failure transport, and the section gives the steps from nats mode
  to `retry_via: nak`, and describes a subscriber a later release may add for one failure-transport copy in nats mode.
  `give_up_via` from PR #119 was not taken: with `retry_via: nak` a message Symfony gives up on is in the failure
  transport already, and keeping it in the stream too worked only on the streams where NATS keeps such a message
  (`docs/PRs/PR-113-symfony-retry-via.md`, "Rejected alternatives").

## [5.9.1] - 2026-11-21

A **patch** release with three fixes for 5.9.0, found while reviewing #119, and the client release they need (#120).
With `retry_via: nak`, the retry of the consumer's last delivery is no longer NAKed and lost when the consumer's
`max_deliver` is not the option's. `scheduled_messages` is refused on a stream with interest retention where nothing
has interest in the delayed subjects, which dropped every delayed message, before `setup()` writes the stream. Nats mode
TERMs a message that cannot be decoded, which NATS brought back for ever. The transport now requires
`idct/php-nats-jetstream-client` `^2.30`.

### Changed

- **`idct/php-nats-jetstream-client` `^2.30`.** A request the server refuses for lack of permission fails at once with
  `PermissionViolationException`, instead of a `TimeoutException` after `request_timeout`, and the transport tells
  such a refusal from a failure of the connection: a worker whose user may not ask for consumer info finished a
  failing message in 1.2 s instead of 6.2 s (`request_timeout` 5 s). A refusal does not make the next operation check
  the connection: the server answered it and keeps the connection open.

### Fixed

- **`retry_via: nak` no longer loses a retry when the consumer's `max_deliver` is not the option's.** The transport
  told the last delivery, whose retry has to go out as a copy, by the `max_deliver` option, while NATS applies the
  consumer's own limit. With the option unset (the default) or at 5 on a consumer limited to 3, as another
  configuration's setup or a change by hand leaves it, the retry of the third delivery was NAKed and NATS redelivered
  nothing: 3 handler runs with `max_retries` 4, no failure-transport copy (measured on nats-server 2.15.2). The
  transport now reads the consumer's limit at the first retry that needs it and again after a minute, follows it,
  and warns once when it differs from the option. A user who may not ask for consumer info, whom the server refuses
  at once (client 2.30.0), leaves the option to decide, with one warning, for that connection; a JetStream error, such
  as a consumer that does not exist, leaves it to decide for that retry only; a read that fails on the connection, as
  a dial during a server restart, makes the send of the retry fail, and the delivery comes back after `ack_wait`
  rather than decided on a guess, which could NAK the retry of a last delivery. Now 5 runs and 1 failure-transport
  copy. `isTheLastDelivery()`, `consumersMaxDeliver()`.
- **`scheduled_messages` is refused on a stream with interest retention that nothing listens on.** NATS stores
  nothing published to a subject no consumer has interest in on such a stream, and the transport's consumer reads the
  topic alone, so every delayed message, published to `{topic}.delayed.{uuid}`, was dropped though its publish was
  acknowledged. With `retry_via: copy`, Symfony's retry copy is such a message: a message that failed once ran once
  and was gone, with no failure-transport copy, on one server and on three replicas (nats-server 2.15.2); with
  `retry_via: nak` the retry of the last delivery `max_deliver` allows was lost the same way. NATS calls it intended
  (ADR-51, nats-server issue #7408). `setup()` and a worker's first pull now read the stream's real retention and
  consumers and refuse it with a `TransportException` that names the ways out, unless a consumer's filter covers the
  delayed subjects. `setup()` refuses it before it creates or updates the stream, with the retention of the stream that
  exists or the one it would create: NATS never turns a stream's schedules off once a write turned them on, so a
  refusal after the write would make the first way out, turning `scheduled_messages` off, fail with "message
  schedules can not be disabled". `assertTheStreamKeepsDelayedMessages()`, `assertTheStreamToSetUpKeepsDelayedMessages()`.

- **Nats mode TERMs a message that cannot be decoded, instead of NAKing it.** A redelivery cannot decode it either.
  When `get()` fails on it (this transport's igbinary serializer, and Symfony Messenger before 8.1), the worker stops
  with the decode error, and NATS brought the NAKed message to every new worker, which stopped again: 6 of 6 worker
  starts exited without `max_deliver` (nats-server 2.15.2). Symfony Messenger 8.1 delivers it as a
  `MessageDecodingFailedException` that fails in the handler instead, and the NAK brought it back to the same worker:
  two workers of 3 s got it 3769 times (Messenger 8.1.9). It is TERMed now in both cases, so only the worker that
  pulled it stops, and only before 8.1. In symfony mode it was TERMed already, and on 8.1 Symfony's retry strategy
  and failure transport handle it as any failure. `get()`, `isAMessageNatsModeCannotDecode()`.

### Documentation

- **A message stopped at `max_deliver` does not always stay in the stream.** `docs/symfony_retry_via.md` said it
  does, and the README told nats mode to rely on NATS's handling of a message that keeps failing. Measured on
  nats-server 2.10.29 to 2.15.2: NATS deletes it from an interest stream inside a cluster, from a replicated
  work-queue stream before 2.12.5, and at a restart from an interest stream on a standalone server before 2.12.9. Both
  documents now give the measured table and what to use on those streams.

## [5.9.0] - 2026-11-18

A **minor** release. It adds `retry_via: nak` for symfony mode: Symfony's retry strategy still decides whether and when a
failed message is retried, and NATS carries the retry out by redelivering the original after a NAK with Symfony's delay, to
the millisecond and on any supported server (#113, proposed by Petar Fedorovsky; closes #109). `scheduled_messages` on a
NATS server before 2.12 is now refused instead of losing every delayed message, and `composer.json` no longer allows Symfony
Messenger 7.2, on which the transport could not load. The public PHP API gains the `RetryVia` enum,
`TransportOption::RETRY_VIA` and `NatsTransportConfiguration::retryVia()`.

### Added
- **`retry_via: copy | nak` for `retry_handler: symfony` (#113, landed through #118; closes #109).** With `nak`, the
  copy Symfony's retry sends is not published. The transport NAKs the failed delivery with the delay Symfony's retry
  strategy computed, to the millisecond and on any supported server, and NATS redelivers the original. A redelivered
  message carries its earlier deliveries in its retry count, so `willRetry()`, the multiplier and `max_retries` work,
  and a message Symfony gives up on is TERMed with one failure-transport copy, as with `copy`, the default. Each
  waiting retry holds one of the consumer's `max_ack_pending` slots. A retry no NAK can carry is published as a copy:
  one without its delivery token, and the retry of the last delivery `max_deliver` allows. The builder refuses
  `retry_via` in nats mode, and a positive `nak_delay` or a `backoff` with `nak`. Based on Petar Fedorovsky's
  proposal. [docs/symfony_retry_via.md](symfony_retry_via.md) is its reference: the terms, the three retry setups
  delivery by delivery, what the server does with a NAK, and what was measured.
- **Seven examples.** `retry-via-nak.php`, `retry-via-nak-edge-cases.php`, `retry-via-nak-past-max-deliver.php`,
  `retry-via-nak-server-behaviour.php`, `retry-via-nak-across-a-restart.php` and `retry-via-copy-or-nak.php` run
  `retry_via: nak` against a live server, most of them under Symfony's own Worker with its retry and failure-transport
  listeners. `delayed-messages.php` shows `scheduled_messages` on a server that has message schedules, and the
  refusal on one that has none. `nats-retry.php` now checks the warning below.

### Changed
- **The nats-mode warning that Symfony's retry strategy is on (#94) names `retry_via: nak`,** and says that Symfony
  still retries a `RecoverableExceptionInterface` with `max_retries: 0`.

### Fixed
- **`scheduled_messages` on a NATS server before 2.12 is refused instead of losing delayed messages.** Such a
  server has no message schedules and ignores the stream setting without a word: `setup()` succeeded, and a message
  sent with a delay, Symfony's retry copy among them, was stored on its delayed subject and never delivered.
  `setup()` now fails before it creates anything, with "The 'scheduled_messages' option requires NATS Server >=
  2.12, but the connected server reports ...", and so does the send of a delayed message. A message without a delay
  is sent as before.
- **`composer.json` allowed Symfony Messenger 7.2, on which the transport cannot load.** Since 5.0.0
  `NatsTransport` implements `CloseableTransportInterface`, which Messenger has from 7.3. The requirement is now
  `^7.3 || ^8.0`.
- **README: `backoff` does not space out failed messages on its own.** It times the redelivery of a delivery
  nobody settled. A plain NAK never uses it, and NATS adds `backoff[n] - backoff[0]` to a NAK with a delay.

### Testing & CI
- **CI's lowest leg runs a Symfony Messenger that rejects a message it sent for retry,** as releases before 7.4.22
  and 8.1.10 do: 7.4.0 in the unit tests and 7.4.21 in the functional test app. Both legs installed a release
  that acknowledges it, so the worker's `reject()` of a retried message ran only where a test called it by hand.
  `scripts/check-messenger-rejects-a-retry.php` fails the leg if that release ever acknowledges instead.
- **A Behat feature, `nats_retry_via.feature`,** runs `retry_via: nak` through `messenger:consume` with a failure
  transport: a message that keeps failing, one that fails once and an unrecoverable failure. It is not tagged
  `@delayed`, so the oldest-server job runs it too.

## [5.8.1] - 2026-11-17

A **patch** release. With `zend.exception_ignore_args` off, the default when PHP loads no php.ini, as in the official Docker
images, an idle worker no longer grows its heap by about 13 KB on every pull that finds no messages, about 0.8 MB a minute, and
a failed publish, ACK, NAK, TERM, message count or setup call no longer leaves a reference cycle behind either (#115, found and
fixed by Matko Anđelinić in #114). A pull while the server is going away, in the last moments of a graceful stop or in lame
duck mode, no longer ends the worker (#106). It requires client 2.29.0, whose `diagnoseNoResponders()` tells such a server
apart from a missing consumer. The public PHP API is unchanged.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.29`.** Client 2.29.0 adds `JetStreamContext::diagnoseNoResponders()`,
  which the fix below uses, and reports a JetStream API call that nothing answers (a server or account without
  JetStream) as `JetStreamException` code 503, as it already reported a pull or a publish, where it let a bare
  `NatsException` through.

### Fixed
- **A pull that finds no messages no longer leaves a reference cycle behind (#115).** The pull ends with a
  `JetStreamException` (408) raised in the event loop, and with `zend.exception_ignore_args` off, the default without a
  php.ini (the official Docker images ship none), its trace recorded the pull's `Future` as an argument of the frame
  waiting on it, while the `Future` held the exception. Each empty pull left about 13 KB of such garbage, about 0.8 MB a
  minute at the default of one pull a second, until the cycle collector ran: after the next handled message (Symfony
  7.3 and later), or once its root buffer filled up, tens of minutes into an idle period. The memory the heap grew to
  stayed with the process. A failed publish, ACK, NAK, TERM, message count or setup call left the same kind of cycle.
  The transport now passes each call to the connection as a closure that starts it, so no trace records its `Future`.
- **A pull while the server is going away, in the last moments of a graceful stop or in lame duck mode, no longer ends
  the worker (#106).** A server that is going away answers a pull with 503 for a consumer it has already stopped, until
  it closes the connection, the same 503 it gives for a consumer that does not exist. In a graceful stop of NATS 2.15
  that moment lasts about 10 ms for each durable consumer on a file stream; in lame duck mode, on every version, it
  lasts the whole grace period (`lame_duck_grace_period`, 10 s by default), as the server stops JetStream at once and
  keeps the connections open. The transport read that 503 as a missing consumer, and a worker ended with
  `JetStream pull request ended with status 503` or, with `auto_setup`, with `Failed to setup NATS stream` after a
  request timeout, as `setup()` ran against the server that was going away, with or without `reconnect: true`. After a
  503 the transport now asks the client why (`diagnoseNoResponders()`), which asks for the consumer once
  (CONSUMER.INFO) while it watches the connection. A server that stays answers at once, with the consumer or with 404,
  and the 503 keeps its reading: `auto_setup` provisions the consumer again, and without it the 503 propagates. A
  server that is going away leaves the question unanswered: a graceful stop shuts the server's eventing down, which
  drops every JetStream API reply, before it stops the first consumer, and lame duck mode stops JetStream before it
  tells the clients it is going away. Once the connection is closed, being recovered or replaced by a reconnect, or
  its server announced lame duck mode, that pull is an empty batch, and the operation after it checks the connection
  first, as after `409 Server Shutdown`. A question that goes unanswered on a connection that stays, as for a user
  allowed to pull but not to ask for consumer info, keeps the 503's reading as well, so a missing consumer still ends
  the worker instead of hiding behind empty pulls. A 503 costs one round trip more; no other status asks anything, and
  once the server has announced lame duck mode nothing is asked. The restart scenarios of the functional suite no
  longer pause 300 ms after the worker connects to stay out of that moment, and a new scenario keeps a worker going
  across a server that leaves in lame duck mode.

## [5.8.0] - 2026-11-07

A **minor** release. NKey and JWT logins work for the first time: the new option `nkey_seed` signs the server's nonce, and a
transport with `jwt` or `nkey` and no seed, which could never connect, is now refused when it is built (#89, #101). With
`retry_handler: nats`, a message whose handler failed is no longer lost on Symfony Messenger 7.4.22 / 8.1.10 and later (#90),
and its NAK is no longer lost when the worker's server goes away during the handler; a transport in that mode warns once when
Symfony's retry strategy is still on (#94). The new option `resend_on_reconnect` sends a publish with a message id again on the
recovered connection. `setup()` waits for a consumer NATS is moving after a change of `stream_replicas` (#98). It requires
client 2.28.0: with `reconnect: true`, a worker whose server goes away while a handler runs now carries on in both retry modes,
the ACK of a handled message and Symfony's retry copy of a failed one go out on the recovered connection instead of being lost or
timing out. The README describes how Symfony Messenger 7.4.22 / 8.1.10 settle a retried message in symfony mode (#92) and what a
handler failing or succeeding during the loss of a server does (#95), with an upgrade note. The mutation score is 98.5%, and the
unit tests and examples that failed now and then are fixed (#102). The public PHP API gains only the `TransportOption` cases of
the two new options; the new methods, `deliveryTime()` and `pause()`, are protected.

### Added
- **NKey authentication: the `nkey_seed` option (#89).** The transport signs the server's nonce with the given seed and
  sends the public key derived from it, so a user the server knows by its NKey can connect with the seed alone. Signing
  needs the `sodium` extension. The value is refused when the transport is built, and never repeated, unless it is a
  user seed (`SU...`) or null: a blank value, a list or another key type's seed would otherwise connect without it
  or fail late. `%env(default::NATS_NKEY_SEED)%` gives null for an optional seed. A username, password or token next to
  the seed, from the DSN or the options, is refused too: a server with both kinds of users would log the connection in
  with the NKey and ignore them, even a wrong password. The seed does not stay in the transport's options.
- **Real logins with a JWT, an NKey and a token in the tests and in CI (#89).** The JWT and NKey logins never worked and
  nothing noticed, because the unit tests stop at what the client is given. `composer nats:start` now also runs
  `nats-jwt` on port 4229, in operator mode, and `nats-token` on port 4230, as a token cannot sit next to a list of users,
  and generates the NKey and JWT credentials with `tests/nats/auth/generate.sh`, which needs docker. None of them is
  committed, for the reason the test TLS keys are not (#9). New Behat scenarios and the examples `nkey-login.php`,
  `jwt-login.php` and `token-login.php` send and consume through each login and expect the server's
  `Authorization Violation` for a wrong credential, in both CI jobs. They also cover a seed and a JWT read from the
  environment in the README's `%env()%` forms, a seed over TLS and mTLS, a bearer JWT,
  an expired JWT and an account without JetStream, a server restart under the NKey and JWT logins, and credentials refused when the
  transport is built, through `messenger:setup-transports`, `messenger:stats` and `messenger:consume`.
  `LoginCombinationsTest` adds the check to `composer test`, with no Docker: all 32 combinations of credentials are refused
  when built, or go through the real client as a CONNECT that carries them.
- **`resend_on_reconnect` (off by default): a send with a message id is sent again on the recovered connection.**
  With `reconnect: true`, a `send()` whose server took the message and went away before it answered, as a stream's
  leader that stops, waited out `request_timeout` on the recovered connection and failed with `TimeoutException`,
  its outcome unknown. The option turns on the client's `resendJetStreamPublishOnReconnect` (client 2.28.0,
  ideaconnect/php-nats-jetstream-client#249): a publish that carries a `Nats-Msg-Id` (`deduplicate: true` or a
  `DeduplicationIdStamp`) is written once more on the recovered connection, within the same `request_timeout`, and
  JetStream drops it when the first copy was stored. Symfony's retry copy is covered the same way. Only a replaced
  connection triggers it, at most once per publish; a publish without an id is never sent again. The builder
  refuses the option without `reconnect: true`, where it would do nothing, and with a `stream_duplicate_window`
  that is not longer than `request_timeout`, where the copy sent again could be stored twice. Off by default for
  the client's two limits, which the README lists: the duplicate window, and on a NATS 2.10 cluster a new stream
  leader that can take the publish before it applied what the old leader replicated.
- **A transport in nats mode warns, once, when Symfony's retry strategy is on (#94).** With
  `retry_handler: nats`, Symfony's retry strategy has no effect and the README asks for `max_retries: 0`, but
  FrameworkBundle gives every transport `max_retries: 3`, and nothing told a user who missed that paragraph:
  Symfony logged every failure as a retry it never made, dispatched `WorkerMessageRetriedEvent` for it, and never
  sent the message to a failure transport (and up to 5.7.1, on Symfony Messenger 7.4.22 / 8.1.10 or later, the
  message was lost, #90). The first time `send()` leaves out the copy Symfony's retry sends, the transport now logs
  `retry_handler=nats on stream "S", subject "T": Symfony's retry strategy retried a message that NATS redelivers
  itself. Set max_retries: 0 on this transport (README, "Retry Handler Behavior").` at warning level, once per
  transport object. It reads no configuration, so it cannot appear with `max_retries: 0` (no copy is sent then)
  and it works with a DSN in an environment variable. The transport keeps the PSR-3 logger it is given (by the
  factory, which the bundle hands the application's `logger`), which until now only went to the NATS client.

### Fixed
- **With `retry_handler: nats`, a message whose handler fails is redelivered again on Symfony Messenger 7.4.22,
  8.1.10 and later (#90).** Those releases acknowledge a message they send for retry instead of rejecting it
  (symfony/symfony#67038). In nats mode the transport does not publish that retry and relies on NATS to
  redeliver the original (#47), so the acknowledgement went to NATS as an ACK: with Symfony's retry strategy
  left on (FrameworkBundle's default `max_retries: 3`), a message whose handler failed once was gone, with
  neither a redelivery nor a failure-transport copy. `send()` now remembers the delivery whose retry copy it
  leaves out, and `ack()` NAKs that delivery (with `nak_delay` when set) instead of acknowledging it. The worker
  of earlier Messenger releases rejects it, which NAKs it as before. The transport still passes the worker's ACK
  on for a delivery that was handled, and for every delivery with the default `retry_handler: symfony`. The
  README's warning says that up to 5.7.1 `max_retries: 0` is required in nats mode on those Symfony releases.
- **`setup()` no longer fails when NATS is still moving the consumer after a change of `stream_replicas` (#98).**
  A stream update that changes the replica count makes NATS move the stream's durable consumer to the stream's
  new set of servers, after it has answered the update. A consumer whose leader was on a server the stream left
  has no leader until a remaining server takes over, and the consumer lookup or create that `setup()` sends next
  could land in that window and fail with err_code 10008, "JetStream system temporarily unavailable" (seen in CI
  and reproduced on nats-server 2.10.29 going from 2 replicas to 1; running the setup again passed). `setup()`
  now sends those two consumer requests again while the server answers 10008, every 100 ms for up to 5 seconds.
  In the same window a consumer request could also be told that the stream `setup()` had just updated was not
  found (err_code 10059), which the lookup read as "no consumer", or get no answer within `request_timeout`:
  `setup()` asks again on 10059 as on 10008, and once more after a request without an answer, whatever the
  5 seconds say. Any other error fails the setup at once, as before, and so do a stream still not found after
  the 5 seconds and a second request without an answer.
- **With `retry_handler: nats`, the NAK of a message whose handler failed while its server went away is no longer
  lost.** The server closes the connection on its way out (a restart, a rolling upgrade, a failover in a
  cluster), and the client reads the socket only while an operation waits for a reply, so the close is still
  unread when the handler fails: the NAK, a write that waits for no reply, was taken by the socket, reached no
  server, and the message came back only after `ack_wait` instead of `nak_delay` (#95 left the cause open; with
  `reconnect: false` the next pull then ended the worker). The NAK or TERM of a failed delivery, from `reject()`
  and from the `ack()` that NAKs a delivery whose retry copy was left out, now checks the connection first with
  one PING, as after a failed operation: the PING's wait reads the close, the client recovers the connection
  (with `reconnect: false` the transport dials another server), and the acknowledgement goes out there. Measured
  on the test cluster (nats-server 2.15.1, client 2.27.2), the handler stopping the worker's server with
  `docker stop`: none of 7 NAKs took effect before (each message came back 30.0 s after its first delivery, also
  when the event loop ran for 1.5 s between the stop and the NAK), 12 of 12 after (1.0 s later, `nak_delay` 1),
  in both `reconnect` modes, through `reject()` and through `ack()`. It costs one round
  trip per failed message; `ping_after_idle: 0` turns it off with the other checks. The ACK of a handled message
  stays a plain write for the transport, with no round trip; client 2.28.0 (below) keeps it from being lost on a
  plain connection. The check stays with that client too: a close behind data not read yet, as a TLS server's
  `close_notify`, is one the client cannot see, and with `reconnect: false` the check dials another server where
  the client would fail the NAK.
- **`jwt` and `nkey` could never connect (#89).** The transport passed them to the client without a signer, and the
  client throws before it writes the CONNECT then, on every server: `requires a nonce signer`, after 43 seconds and as
  `Reconnect attempts exhausted` with `reconnect: true`. `jwt` now works next to `nkey_seed`, its user's seed: a `.creds`
  file holds both. Each one with no signer, which means no seed today, is refused when the transport is built, naming
  `nkey_seed`, and so is a `jwt`
  that does not have the three parts of a JWT, such as a seed or a whole `.creds` file, which the CONNECT would otherwise
  carry to the server. `nkey` stays
  optional and is best left unset, as the key is derived from the seed; with a seed it must be that key, and the error
  names the seed's key and does not repeat the value. No login that worked is lost, unless a subclass that replaces
  `$client` still passes these options, and the error comes earlier, so see the README's "Upgrading from 5.7.1" for
  `messenger:stats`, for workers on several transports and for such a subclass.
- **A failed build no longer keeps a seed, password or token in its stack frames (#89).** With exception arguments on
  (`zend.exception_ignore_args=0`, PHP's default without a php.ini), the frames of the factory, the transport's
  constructor and the builder held the options and the DSN. Every such parameter is now `#[\SensitiveParameter]`, and so
  are those of the builder's helpers that take one value of them. A
  mistyped seed no longer chains the client's exception, whose frames hold the value, and neither does a sodium function
  the client cannot call. Symfony Messenger's factory marks
  its options only from 7.4.23 and 8.1.11, and the client marks nothing yet, so the README recommends turning those
  arguments off in production.
- **An error no longer repeats a name or value that may hold a credential (#89).** A query typed with `&` or `;` instead of
  `?`, or with a second `?`, stays in the stream or topic name or in the value of the option before it, and the errors for
  those printed it. They now repeat a name or value only if it is at most 50 plain characters, and still say what is
  allowed.
- **A `max_reconnect_attempts` float past the range of an int is refused (#89).** It did not survive the cast on its way to
  the client: `1e19` became a negative number, which the client's constructor refused with every credential in its frame,
  naming no option the user set, `2e19` an unrelated huge number, which worked, and `1e300` or `.inf` became 0. So did a
  numeric string past the largest float, such as `1e400`. Each is now refused when the transport is built, naming the option.
  An int of any size, and any other numeric string, which stops at the largest int, still work.
- **A credential typed after a second `?` or a `;` in the DSN query is refused when the transport is built (#89).** It stayed
  in the value of the option before it and went where that goes: into the TLS library's error for a file name, or to
  the server, which stores it, for a consumer name, a stream description or a placement. Only the DSN query is checked,
  for `nkey_seed`, `nkey`, `jwt`, `token`, `username`, `password` and `tls_key_passphrase`, also with a space next to the
  name or in another case, as nothing splits a value in the options. An encoded `&`, a double encoding, a name with brackets
  or a dot, a literal control character and a space outside ASCII stay a part of the value.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.28` (was `^2.27.1`), so that a write after the server closed the
  connection unread no longer vanishes (client #252).** The client reads the socket only while an operation waits
  for a reply, so the close of a server that stops while a handler runs stayed unread, and the next write went into
  the closed socket: the ACK of a handled message was lost, and the message came back after `ack_wait` to be
  handled again; Symfony's retry copy of a failed one waited out `request_timeout` and its `TimeoutException`
  stopped the worker (with `reconnect: true`). Client 2.28.0 asks the socket, before a publish or request write,
  whether the server has closed it, and handles a yes as a failed write. Measured on the test cluster (nats-server
  2.15.1, Symfony Messenger 8.1.10), the handler stopping the worker's server with `docker stop`: with
  `reconnect: true` the worker carries on in both retry modes, the retry copy is stored through another server and
  handled 0.3 s after the first delivery, and the ACK reaches the cluster; with `reconnect: false` the ACK or retry
  copy fails at once with `Reconnect is disabled` and the worker exits, as the next pull used to, and the message
  comes back after `ack_wait`. A first `send()` right after the stop is stored at once through another server
  with `reconnect: true`, where it took 10 s and a `TimeoutException`. `composer update` installs it; the root and the
  functional test app require the same version, and CI tests 2.28.0 as both the lowest and the latest release.
- **README: with the default `retry_handler: symfony`, a message that Symfony Messenger 7.4.22, 8.1.10 and later
  send for retry is ACKed, not TERMed (#92).** Since symfony/symfony#67038 the worker acknowledges a failed
  message once its retry copy is sent instead of rejecting it, and the transport passes that ACK on. The "Retry
  Handler Behavior" section now says so: a `TERM`, and its JetStream `MSG_TERMINATED` advisory, marks only a
  failure Symfony treats as final or a message that could not be decoded, so counts or alerts built on those
  advisories drop after such a Symfony upgrade, with no message lost. The transport's behaviour is unchanged.
- **README: what a handler failing or succeeding while the worker's server goes away does (#95).** The "Losing a
  Server" section said a worker polls on with `reconnect: true`; a new part of it, "A Handler Failing During the
  Loss", describes the moment a handler runs into the loss: with client 2.28.0 and `reconnect: true` the worker
  carries on in both retry modes, and with `reconnect: false` an ACK or Symfony's retry copy ends it, while a NAK in
  nats mode goes out through another server. It names what the client still cannot see (a close behind unread
  data, as over TLS) and a copy the server took and could not answer, which only `resend_on_reconnect` sends again,
  gives the measurements on the test cluster for both retry modes and both `reconnect` modes, and recommends
  `deduplicate: true` with a `stream_duplicate_window` longer than `ack_wait` plus the handler's run, and idempotent
  handlers. "Automatic Reconnect" and "Losing the Connection" point to it, and the "Losing a Server" table is
  measured again with client 2.28.0.

### Testing & CI
- A Behat scenario, "A message that fails once is redelivered with Symfony's default retry strategy", sends a
  message whose handler fails once to a transport in nats mode with Symfony's default retry strategy, and
  checks that it is handled on the redelivery; it fails on Symfony Messenger 8.1.10 without the fix. Unit tests
  run Symfony's Worker over a batch with one failing and one handled message, and cover the NAK on `ack()`
  (with `nak_delay` and `ack_sync`), its single use, and that only the delivery sent for retry is NAKed.
- A new example, `nats-retry.php`, runs Symfony's Worker and retry listener with FrameworkBundle's default retry
  strategy against the test server, in nats mode, over a message whose handler fails once: NATS redelivers it
  `nak_delay` later and it is handled, with Symfony's retry left unpublished. `composer examples` runs it in CI
  on the latest and the oldest supported NATS, and it fails without the fix on Symfony Messenger 8.1.10.
- The warning about Symfony's retry strategy in nats mode (#94): unit tests check that it is logged once for two
  left-out copies through Symfony's Worker, once per transport object with its own stream and subject, and not
  at all in symfony mode, with `max_retries: 0`, or for a copy that is not a retry (failure transport, message not
  received in this process, plain dispatch). The Behat scenario "Symfony's retry strategy is ignored with the NATS
  retry handler" checks that the consumer warned once, and "NATS stops redelivering a poison message after
  max_deliver attempts" (`max_retries: 0`) that it did not.
- The consumer requests `setup()` sends again while the cluster answers 10008: unit tests cover the lookup and
  the create asked again after a pause of 100 ms, the real pause, the setup failing with the server's message
  once 5 seconds have passed, and no second request after another JetStream error. The example
  `stream-placement.php`, whose staged step from 2 replicas to 1 failed this way on NATS 2.10 in CI, covers it on
  the three-server test cluster. Further unit tests cover 10059 asked again (the lookup no longer reading it as
  "no consumer"), giving up on it after 5 seconds, 10014 still read as "no consumer" at once, an unanswered
  lookup and create each asked once more, and a second unanswered request ending the setup. The test doubles'
  `pause()` fails a test once it ran more often than `setup()` ever pauses, so a loop that never ends fails in
  seconds instead of hanging the run (the mutant that drops `return $request();` used to time out after 60 s).
- The NAK of a failed delivery after the server closed the connection during the handler: a real-client test
  (`ReconnectModeTest`, against a connection the scripted server closed without the client reading it,
  `FaultInjectingNatsServer::closeUnread()`) checks that the NAK of `reject()`, and of `ack()` after the retry
  copy was left out, reaches the server on the recovered connection, and fails without the check. Unit tests check
  the PING before the TERM and the NAK (with and without `nak_delay`) and none with `ping_after_idle: 0`. A new
  example, `handler-failing-during-failover.php`, runs Symfony's Worker on the test cluster with `reconnect: true`;
  its handler stops the worker's server and fails, and the message has to come back to the same worker. It fails
  on 5.7.1.
- Client 2.28.0: the scripted connection answers the client's new `peerClosed()` question, for a close on a plain
  socket and for one behind unread bytes, which the client cannot see (as over TLS). Real-client tests check that
  after such a close the ACK of a handled message goes out on the recovered connection with no PING first, that
  Symfony's retry copy does, that the NAK's PING still saves the NAK behind unread bytes, where the ACK is still
  lost (a guard), and that a send whose server went down right after it took the frame is written again on the
  recovered connection only with `resend_on_reconnect` and a `Nats-Msg-Id`, and otherwise times out. Builder tests
  cover the option's default, its mapping from options and DSN, and both refusals. The example
  `handler-failing-during-failover.php` now runs in both retry modes, and a new one,
  `handler-succeeding-during-failover.php`, has the handler succeed in both: its ACK has to reach the cluster
  through another server. Both fail with client 2.27.2: the retry copy's publish ended the worker with
  `TimeoutException`, and the ACK stayed pending until `ack_wait`.
- Mutation testing: 17 of the 32 surviving mutants are gone. Two are killed by tests that give `send()` the
  delivery time of a delayed message (a new protected `deliveryTime()`, the only seam), so a whole second is
  checked not to be rounded up. The others were equivalent, and the code they mutated said the same thing twice:
  a guard that a later check covers (`get()`'s check for no headers, the early returns of
  `assertStreamDescriptionLength()`, `assertDuplicateWindowNotExceedingMaxAge()` and `toNullableString()`, the
  type checks before `is_numeric()` in `assertBackoff()`, the empty-segment check after `trim()` in
  `parseStreamAndTopic()`), a comparison that is always true (`streamReplicas() > 0`, `streamMaxAgeSeconds() > 0`
  around a product that is 0 then), a cast PHP makes anyway, and a second source for the same value (the stream's
  subjects). These spots now say it once; the behaviour is the same, and the unit suite passes unchanged.
- The bundle test expects the wording of Symfony Messenger 7.4.23 and 8.1.11, whose change had turned `composer test` red.
  It still passes on the earlier versions.
- `ext-sodium` is in `require-dev`, so `composer install` asks contributors for it, and every CI job has it: losing it
  now fails instead of skipping the tests of NKey authentication.
- `composer test:functional` runs without Composer's process timeout, as the scenarios take about as long as its limit of
  600 seconds, and past it Composer left Behat running.
- The worker restart scenarios wait for the worker's connection, as on CI's runners it used to come after the restart, so
  they now prove the reconnect. They then pause 300 ms to stay out of a gap the README now names: a graceful stop of NATS 2.15
  leaves a moment, about 10 ms for each durable consumer on a file stream, in which a pull can get 503, and that ends a
  worker. The gap is not new, and it stays for now (#106).
- **The unit tests no longer depend on how fast their process runs (#102).** `ReconnectModeTest` gave every operation 20 to 100 ms,
  so a machine short of CPU failed it, with `the send waited for the held dial` or a request that timed out. An operation that
  has to succeed now gets a budget it never uses up, only the send a test runs out of its budget gets a short one, and a
  recovery a test looks at waits for the test instead of for a time. The class also runs faster, 0.9 s instead of 2.2 s.
- `SubscriptionLimitPullTest` and a connection check test bounded how long a failure took too tightly for a process that was
  paused. Their bounds now sit far from what the failure normally takes (#102).
- **`composer test:unit:paused`, also in CI (#102),** runs the unit tests and stops their process at random moments, so that a test
  that depends on how fast its process runs fails every time, not just now and then on a busy machine.
- **The `keepalive` example no longer fails now and then (#102).** It read the queue depth right after a plain ACK, which nothing
  waits for. It now waits for the count to reach 0, and a lost ACK still fails it.
- **The failover examples no longer fail now and then on NATS 2.10 (#102),** with `no suitable peers for placement, peer offline`.
  After a server rejoins the cluster, the examples now wait until a stream with three replicas can really be created. A server
  answering JetStream requests did not show that.

## [5.7.1] - 2026-10-19

A **patch** release. It requires client 2.27.1, in which an acknowledgement whose write is the first to notice a
lost connection no longer waits for the client's recovery when `reconnect` is on, and it adds three examples, for
the reconnect mode, a failover in a three-server NATS cluster and stream placement, with the test cluster they
run on. The README has a section on using a NATS cluster, a Logging section and an upgrade note. The transport's
code and its public PHP API are unchanged.

### Added
- **Examples for the reconnect mode, cluster failover and stream placement, and a test cluster to run them on
  (#84).** `composer nats:start` also runs a JetStream cluster `east` of three servers
  (`tests/nats/nats-cluster*.conf`, ports 4226 to 4228, two servers tagged `disk:ssd` and one `disk:hdd`), and
  `composer examples` runs three more examples, in CI on the latest and the oldest supported NATS as before:
  `reconnect.php` restarts the test server under a worker with `reconnect: true` and `auto_setup`: the pull in
  flight ends empty, no pull throws, and the memory stream the restart lost is recreated by the next send;
  `cluster-failover.php` stops the server a worker is connected to, and the client fails over to a server it
  learned from the cluster while the worker receives every message; `stream-placement.php` places replicas on
  tagged servers, changes one half of a placement, and stages a move that comes with a replica change after
  NATS refused it in one update. The two that take a server away do it with docker and start it again. Both
  reconnect examples hand the transport a logger and print what the NATS client logged.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.27.1` (was `^2.27`), so that with `reconnect: true` an
  acknowledgement no longer waits for the client's recovery (client #244).** With client 2.27.0, an ACK, NAK or
  TERM whose own write was the first to notice a lost connection, while the client still reported it open, ran
  the client's recovery inline and retried the write, with no budget of its own: one `ack()` or `reject()` could
  wait for a whole attempt loop, about 109 s at the client's defaults against a server that refuses connections.
  Client 2.27.1 puts such a frame into the reconnect buffer and returns at once, as it already did for a frame
  written while the recovery was under way, and the recovery sends it on the connection it makes; a recovery that
  gives up discards it with the rest of the buffer and says so at error level, as the README describes. The
  5.7.0 README and release notes pointed at the client issue as open. With the option off nothing changes: the
  client runs with reconnect off and a failed write fails the acknowledgement as before. A new real-client test,
  over a connection whose ACK write fails while the next dial is held 300 ms, has `ack()` and `reject()` return in
  under 0.1 s and the frame reach the server on the recovered connection; on 2.27.0 both calls took 0.31 s.
- **README:** an "Upgrading from 5.6" note (nothing to change; the two differences that apply in either mode), a
  Logging section (what the NATS client logs at which level, how the bundle wires the application's `logger`, how to
  wire one without the bundle), a note on a server that stops in the provisioning section, a troubleshooting entry
  for "Reconnect attempts exhausted", and the logger and the two recovery modes in the architecture overview.
- **README: a "Using a NATS Cluster" section, and the reconnect and placement options checked against the
  README (#86).** The section covers how a process finds the servers (one in the DSN, the others learned from
  it through `connect_urls`, `client_advertise`, and why the DSN should name more than one server for the first
  dial), what each reconnect mode does when the server it is connected to stops, with figures measured on the
  test cluster, replicas, placement, provisioning and leader changes on a cluster, and TLS on a cluster:
  `tls_required: true` next to the `+tls` scheme, without which the client dials the servers it learned without
  TLS, and the certificate name it checks. It points at the `cluster-failover.php` and `stream-placement.php`
  examples. Elsewhere: the DSN form of `reconnect` and `max_reconnect_attempts`, that more than one replica
  needs a cluster and that an existing stream keeps its replica count while `stream_replicas` is unset, a cluster
  bullet in the features, and two troubleshooting entries.

## [5.7.0] - 2026-10-17

A **minor** release. It adds a reconnect mode: with `reconnect: true` the NATS client recovers a lost connection
on its own and the transport leaves it to that, so a worker polls through an outage instead of exiting, and
provisioning is verified again once the client reconnected. The NATS client logs through the application's
logger, and a pull the server ended for its own shutdown or a leadership change is an empty batch rather than a
missing consumer. With the option off, which is the default, the transport behaves as before.

### Added
- **`reconnect` and `max_reconnect_attempts` options: a mode in which the NATS client owns the recovery of a
  lost connection (#45, #81).** Off by default, which is what the transport did before: the client runs with
  reconnect off, the operation that runs into the lost connection fails, the next one dials again, a worker
  exits and its supervisor restarts it. With `reconnect: true` the client re-dials in the background with its
  backoff, up to `max_reconnect_attempts` times per recovery (default `null`, the client's own 10), replays
  its subscriptions and flushes the frames it buffered, and the transport steps back. A client in the middle
  of its recovery is left alone, with no PING and no dial of the transport's own, and the operation waits for
  the recovery within its own budget: `request_timeout` for a request, `max_batch_timeout` plus a second for
  a pull, which then ends empty. After a PING that failed, a client that is recovering or has already
  recovered keeps its connection, a connection the client still reports open with no reconnect behind it is
  replaced as without the option, and a client that gave up is Closed and dialled again, which runs the
  client's attempt loop inside the operation and propagates its exception. With `auto_setup`, provisioning is
  verified again once the client's reconnect count moved, since the client replaced the connection behind the
  transport's back and the stream may have gone with the server; a send begun during the recovery that lands
  on a server without the stream gets one `setup()` and one more publish of the same frame on the client's 503
  "No JetStream responder". Measured with nats-server 2.14.2 over a 53 s `docker stop`: with the client's 10
  attempts every operation waited within its budget and none dialled, with 2 attempts every operation after
  the recovery gave up dialled inline for 18 s. Before this, the option as proposed in #45 ran the whole
  attempt loop inside every operation during an outage, 109 s each at the defaults. The README's Automatic
  Reconnect section has the table, the per-operation bounds and the two places the loop still runs inline.
  Both options work from the DSN query string too. Two Behat scenarios restart the test server with docker
  under a running worker and under a process that sends again after a quiet period.
- **The NATS client logs through the application's logger (#81).** `NatsTransportFactory` takes an optional PSR-3
  logger and hands it to every transport it creates, `NatsTransport` takes it as a fourth constructor argument,
  and the bundle passes the application's `logger` service when there is one, under Messenger's Monolog
  channel. The client writes its connection lifecycle at info level and its errors at error level; among them
  `Reconnect exhausted: N bytes of buffered publishes were discarded`, the only trace of acknowledgements
  written during an outage that a reconnect which gave up discarded (the messages come back after `ack_wait`).
  Those went to a null logger before. `psr/log` `^3.0` is a direct requirement now; it was installed already,
  through the client.

### Fixed
- **A pull the server ended for its shutdown or for a leadership change is an empty batch, not a missing
  consumer (#81).** A server that is shutting down ends every pull in flight with `409 Server Shutdown`, and a
  consumer whose leader moved ends them with `409 Leadership Change`. Every 409 used to read as a missing
  consumer: with `auto_setup`, `setup()` ran against the server that was going away and failed, so a worker
  exited with "Failed to setup NATS stream" on every graceful stop of its server, and without `auto_setup` the
  409 propagated and ended the worker as well. Both read as an empty batch now, on the first pull and on the
  one retried after re-provisioning, and after a shutdown the next operation checks the connection first, since
  the server closes it right after. Only those two descriptions: `Consumer Deleted` is still a missing consumer,
  and the 409s that report configuration (`Consumer is push based`, `Exceeded MaxRequestBatch`) still propagate.

## [5.6.0] - 2026-10-17

A **minor** release. It adds the `stream_placement_cluster` and `stream_placement_tags` options, which pin a
stream to a JetStream cluster and to tagged servers. On an existing stream each option replaces only its own
field of the live placement, and a tag list that names the live set in another order is left as the server
stores it. A placement change combined with a `stream_replicas` change, which NATS refuses in one update, fails
`setup()` with the steps to stage it. Nothing changes for a configuration that sets neither option.

### Added
- **Persistent project memory** in `docs/PROJECT_MEMORY.md`, linked from `AGENTS.md` and `CLAUDE.md`, covering the
  maintainer's local client-library workflow, transport behavior, verification requirements, and
  a dated GitHub issue and development-environment snapshot.
- **`stream_placement_cluster` and `stream_placement_tags` options** exposing JetStream stream placement
  (`placement.cluster` / `placement.tags`). Tags accept a YAML list or a comma-separated string, so they
  work from a DSN query string as `stream_placement_tags=ssd,eu-west`. Both default to `null`, which
  leaves the stream's placement untouched: on the update path the transport echoes the live server value
  back, because a JetStream update that omits `placement` clears it (verified against nats-server 2.14).
  On an existing stream each option replaces only its own field of the live block, so `stream_placement_tags`
  alone keeps a cluster pin an operator set in NATS and `stream_placement_cluster` alone keeps the live
  tags, and configured tags that name the live set in another order keep the live list as the server
  stores it: nats-server reads any difference in the block, the order of the tags included, as a request
  to move the stream's replicas, and refuses a move combined with a replica change. The tags are compared
  exactly, since the server's `unique_tag` bypass is case-sensitive (#80).
- **A placement change combined with a `stream_replicas` change on an existing stream fails with staging
  advice.** nats-server refuses to move and scale a stream in one update (err_code 10123). `setup()` keeps
  its `RuntimeException`, the server's wording and the client's `JetStreamException` as the previous
  exception, and adds how to stage the two changes: apply one, wait until `nats stream info` shows every
  replica as current, then apply the other. The update is not split by the transport, because with
  `auto_setup` a `setup()` that applied one half would latch the done flag and never apply the other;
  instead every `send()` and `get()` repeats the rejected update and fails until the configuration is
  staged, and nothing is published or consumed meanwhile (#80).
- **`TypeCoercion::stringListValue()` and `TypeCoercion::nonBlankStringValue()`** centralizing the
  list-or-comma-separated-string coercion used by `stream_placement_tags` and the trimmed-name coercion used
  by `stream_placement_cluster`. The builder validates with them and the configuration accessors read with
  them, so a configuration constructed directly with raw values is read the way the builder would have
  stored it.

## [5.5.0] - 2026-10-16

A **minor** release. It adds `IDCTNatsMessengerBundle`, which Symfony Flex enables and which registers the
transport factory and the igbinary serializer, so `composer require` and a DSN are all an application needs. A
pull refused at the connection's subscription limit now fails `get()` with a `TransportException` naming the
limit, and the transport requires client 2.27. The development dependencies move to Behat 4 and PHPUnit 11 to 13.
Applications that name the `igbinary_serializer` id should read "Upgrading from 5.4 or earlier" in the README.

### Added
- **`IDCTNatsMessengerBundle`** - a Symfony bundle that registers the transport factory and the
  igbinary serializer automatically, each under its class name (for the serializer, the README's
  `serializer:` value), so projects no longer need manual `services.yaml` entries to use the transport.
  Flex enables it when the package is installed or updated, and going back to an earlier version leaves
  it in `config/bundles.php`, to be deleted by hand (and added back by hand on a later update, since Flex
  configures a package once). Existing entries keep working next to it. It does
  not register the `igbinary_serializer` id earlier READMEs suggested, so a transport that names that id
  needs the class name before its entry is deleted (README, "Upgrading from 5.4 or earlier").

### Fixed
- **A pull refused at the connection's subscription limit fails `get()` with a `TransportException` that names the
  limit (#76).** The transport holds up to two subscriptions on a connection: the reply inbox of its requests, which
  the first `send()`, `ack_sync` acknowledgement, `setup()` or `getMessageCount()` subscribes, and the reply inbox of
  the pull in progress. With a per-connection limit of one (the server's `max_subscriptions`, or an account's or
  user's `subs`), the server refuses the pull's inbox with `maximum subscriptions exceeded`, and every pull after the
  first request on that connection fails. The client's `ConnectionException` left `get()` as it was, though Symfony's
  `ReceiverInterface::get()` declares a `TransportException`, and when the client's heartbeat met the error first its
  message ended "A retry subscribes a new one", which is wrong advice here. `get()` now throws a `TransportException`
  naming the stream, the consumer and the limit, and saying the transport needs at least two subscriptions per
  connection, with the client's `SubscriptionLimitException` as its previous exception. The second pull after
  `auto_setup` provisioned the consumer again is covered too. Like any exception from `get()`, it ends a worker's
  run; nothing is lost, and nothing hangs. The next operation still checks the connection first: the server sends the
  same error when an account's limit is lowered below what the connection holds, and closes the connection about
  20 ms later, so the error cannot prove the connection is still there. Other failures of a pull are unchanged.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.27` instead of `^2.10.3`.** 2.27.0 reports the subscription limit
  as `SubscriptionLimitException`, a `ConnectionException`, wherever it used to build a plain one for it, so the
  transport recognises the limit by its type rather than by the error's wording (see Fixed). The functional test app
  requires the same release.
- **Tests for a pull at the subscription limit** (`tests/unit/SubscriptionLimitPullTest.php`), running the real client
  over a scripted server (`tests/Support/SubscriptionLimitNatsServer.php`) that refuses a SUB beyond its limit, keeping
  the connection open, and can send the limit error and close the connection 20 ms later, as nats-server does when an
  account's limit is lowered. They cover a refusal the pull's own read meets and one the heartbeat's read meets,
  delivery and acknowledgement at a limit of two, and the closing error before and after the pull's inbox was
  confirmed, with the client still reporting the connection Open and with the client Closed. The transport's checks
  are counted through `rtt()` (`tests/Support/RttCountingNatsClient.php`). `ClientInjectedNatsTransport` moved from
  `tests/unit/UnansweredPullTest.php` to `tests/Support/`, so both files can use it.
- **The unanswered-pull unit test tells the client's inbox fence from a connection check.** Client 2.19.0 writes a
  PING behind each pull inbox's SUB in the same write, to learn whether the server took the subscription, and its
  scripted server (`PullAnsweringServer` in `tests/unit/UnansweredPullTest.php`) counted that PING as a check the
  transport made, failing `testOnlyAPullTheServerDidNotAnswerMakesTheNextOneCheckTheConnection` against that client.
  The server still answers every PING but records only one written on its own. The test passes against client
  2.10.3 and 2.19.0; the transport is unchanged.
- **Development dependencies upgraded (#73, #74).** Behat 4.0 in the root and in the functional test app, PHPUnit
  `^11.5 || ^12.5 || ^13.4` (PHP 8.2 installs 11, PHP 8.5 installs 13), Infection up to 0.35 (which needs PHP 8.3, so
  PHP 8.2 keeps 0.32), PHPStan 2.3 and BypassFinals 1.11. The functional test app requires the newest Symfony 7.4 and
  8.1 releases, Flex 2.11 and MakerBundle 1.68. Behat 4 reads neither YAML nor doc-comment annotations, so
  `tests/functional/behat.yml` became `behat.php` (converted with `behat --convert-config`) and the context's step
  definitions and hook became attributes (converted with Rector's Behat attribute set). Under PHPUnit 13 a test
  double that sets no expectations is made with `createStub()` instead of `createMock()`, and `phpunit.xml.dist`
  drops the deprecated `depends` execution order (PHPUnit resolves test dependencies by default). The
  commented-out cache step in CI names `actions/cache@v6`; the other actions were already on their newest major
  versions. The library's runtime requirements are unchanged.
- **The functional suite fails on undefined and pending steps.** `tests/functional/behat.php` turns on strict result
  interpretation. Behat 4 registers step definitions from attributes only, so a step left as a doc-comment annotation
  is undefined, and a scenario using it was reported as undefined while the run, in CI and through
  `composer test:functional`, exited 0. A dry run now always exits 1, since it skips every step, so `run_tests.sh`
  leaves its exit code aside and the functional README says to read its summary. Guarded by
  `FunctionalSuiteConfigTest`.
- **`run_tests.sh` option 6 and the functional README list the step definitions with `--definitions l`.** Behat 4
  requires a value for `--definitions`, so both printed "The "--definitions" option requires a value." and exited 1.
- **PHPUnit deprecations, and on PHPUnit 13 PHPUnit notices, fail the unit suite.** `phpunit.xml.dist` sets
  `failOnPhpunitDeprecation`, and CI's PHP 8.5 job runs the suite with `--fail-on-phpunit-notice`, the option for
  the notice PHPUnit 12 and later give a test double that sets no expectations. Both used to be reported while the
  run passed. PHPUnit 11, which PHP 8.2 installs, does not know `failOnPhpunitNotice` and rejects the whole file
  when it sees it, so the notice option stays on the command line. Guarded by `UnitSuiteConfigTest`.
- **The serialization-failure send test checks that nothing is published.** Its JetStream double became a stub
  for PHPUnit 13, which silenced the notice but left the test unable to see a `send()` that publishes before
  rethrowing the serializer's exception; it is a mock again, expecting `publish()` never to be called.

## [5.4.1] - 2026-10-05

A **patch** release. It fixes duplicate protection for transports that share a stream and the connection
check around empty and unanswered pulls, leaves integer header names out of `decode()`, makes the mutation
gate run the tests it scores, and requires client 2.10.3. Every `Nats-Msg-Id` changes with the upgrade (see
Fixed).

### Fixed
- **With `deduplicate`, a message routed to two transports on one stream is stored for each.** Symfony hands
  each transport a message is routed to the envelope the one before returned, deduplication id included, and
  JetStream deduplicates per stream whatever the subject, so the second transport's copy was dropped, and
  so were the failure-transport copies of a message that failed on two transports. The `Nats-Msg-Id` now
  holds the transport's subject too (`<id>:<topic>:<retry count>`), and on a failure-transport copy the
  transport the message failed on (`:failed:<receiver>`). Every id changes with the upgrade, so a message
  sent once before it and again after it, within the stream's `duplicate_window`, is stored twice, and so
  is one that processes still on 5.4.0 and processes already upgraded both send, as during a rolling deploy.
- **An empty pull counts as using the connection.** It was counted only when messages came, so with
  `max_batch_timeout` at or above `ping_after_idle` every pull after an empty one sent a PING first, with
  one `connection_timeout` for the answer, and one slow answer dropped a healthy connection.
- **A pull the server did not answer makes the next operation check the connection.** The client reports a
  pull that got no answer before its own deadline, `max_batch_timeout` plus a second, as a JetStream error
  with status 408, like the server's own end of a pull that found no messages, so it read as an empty
  batch and called for no check. A half-open connection takes every write and delivers nothing: a consumer
  on one pulled empty batches until the client's heartbeat noticed, about 90 seconds with its defaults. The
  pull still reads as empty, and the next operation PINGs first, so the pull after it runs on a new
  connection.
- **A header whose name is a decimal integer is no longer passed to the serializer.** Any publisher can send
  a header named `1` or `-5`, which the client returns as an int key, since PHP stores such array keys as
  integers. `get()` handed it to the serializer's `decode()`, whose contract takes string names, so a
  serializer relying on that under strict types failed with a `TypeError`: the message was rejected (TERMed
  by default, NAKed and redelivered into the same failure with `retry_handler: nats`) and the worker
  stopped. Symfony's serializers and this transport's ignore such a name and were not affected. No string
  key can hold such a name, so `get()` now leaves those headers out. Client 2.10.3 declares the int keys,
  which is how PHPStan found the call.
- **The mutation gate counted every mutant as killed.** Infection runs each mutant in a PHPUnit process
  whose bootstrap puts its include interceptor on `file://`. The interceptor registers itself again after
  every file operation, which removed the wrapper BypassFinals puts on top of it, so in those processes the
  client's final classes could not be doubled: every test that doubles one failed with
  `ClassIsFinalException`, and Infection counted the mutant as killed whatever the tests checked. That error
  accounted for 462 of the 857 kills, and a run whose mutants changed nothing at all scored 93%. The `minMsi`
  90 and `minCoveredMsi` 95 floors passed whatever the tests caught, and the earlier MSI figures were not real:
  the 100% in the README, in `docs/TESTS.md` and in older entries of this changelog, and the 5.4.0 release
  notes' "mutation testing on the changed lines left no surviving mutant". `tests/bootstrap.php` now takes the
  interceptor's file swap over in a mutant process, also when Infection runs from its PHAR, so mutants run
  the tests as a plain run does. Measured with this fix, the real score was 96.3% (825 of 857): 50 mutants
  that had counted as killed escaped, and the tests listed under Added kill them; `docs/TESTS.md` lists the
  32 left, with the reason for each. The floors stay at 90 and 95. Two new checks, which CI runs around the
  mutation run, keep it honest: `composer test:mutation:canary` fails when a mutant that changes nothing is
  detected, and `composer test:mutation:check-log` fails when `infection.log` shows a mutant killed by the
  harness rather than by a test (`composer test:mutation` now logs every mutant's output for it). When a
  mutation step fails, CI uploads `infection.log`.
- **Docs:** the README said the check after a failure catches a connection that silently stopped delivering,
  which `get()` did not do until the fix above. It also gives the PING's real bound, `connection_timeout` or
  the shorter `request_timeout`, for both checks. `CLAUDE.md`, `AGENTS.md` and `STRUCTURE.md` describe the
  behaviour added since 5.2.0.

### Added
- **A unit test that runs the real client for the unanswered pull**, against an in-memory server, so a
  client version that words its own pull deadline differently fails the suite instead of turning the check
  off.
- **Functional scenarios for the idle-connection check**, against a new test server that drops idle clients
  within seconds (`nats-stale`, port 4225): a message sent after the server dropped the idle connection goes
  out, and without the check it fails.
- **A functional scenario for duplicate protection across two transports on one stream**, which fails on
  5.4.0 with one message stored instead of two.
- **Runnable examples** in `examples/`, one per behaviour: sending and consuming, duplicate protection, the
  request timeout, keepalive from a signal handler, and the connection checks. Each prints `OK` when what it
  shows held. `composer examples` runs them against the test server, and CI runs them after the functional
  suite on the latest and the oldest supported NATS.
- **Unit tests for configuration checks that no test covered.** A mutation run whose mutants really ran
  the tests changed each of these without a test failing: the port in the DSN, an upper-case `+tls` scheme
  or `stream_storage` value, timeouts below half a millisecond or set to `null`, the client's reconnect and
  pedantic mode staying off, the `inactive_threshold` check, each tri-state stream flag after the first,
  zero and fractional `backoff` entries, `max_deliver` without `backoff`, a `null` `stream_max_age` with a
  duplicate window, and credential options given as numbers, booleans or arrays.
- **Unit tests for configuration defaults and floors that no test covered**, found the same way: `ack_sync`
  off when the configuration is built without it, the 1-second connection timeout and the 0 ms NAK delay
  when the options are missing, negative `nak_delay` and `backoff` values read as 0 and a `backoff` entry of
  0 kept as 0, and seconds converted to milliseconds rounding a fraction under half a millisecond down.
- **Unit tests for transport behaviour that no test covered**, found the same way: the rest of a batch after
  a message without a reply subject, a message without a `DelayStamp` on a transport with
  `scheduled_messages`, a configured `stream_max_bytes` on an existing stream, subjects in the server's
  config that are not non-empty strings, a connection last used exactly `ping_after_idle` ago, `auto_setup`
  after a pull error that does not mean a missing consumer and after a 408 from the retried pull, a stream
  state without a message count, the message for an unsupported feature while `scheduled_messages` is on,
  and the previous exception of the errors `send()` and `setup()` throw. The test for a delay of under a
  second no longer depends on the clock: it missed a change to the rounding in about one run in three.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.10.3` instead of `^2.10`.** 2.10.3 declares the int keys of
  a header name that is a decimal integer (see Fixed), and it no longer closes the connection on an `-ERR` the
  server keeps the connection open for, such as `maximum subscriptions exceeded` for a subscription beyond an
  account's or user's limit, as 2.10.1 and 2.10.2 did: the operation that read it fails, and the transport
  goes on using the connection instead of dialling again. At the subscription limit, after the server has
  rejected the reply inbox that requests share (the publish acknowledgement of `send()`, an ACK with
  `ack_sync`, the JetStream API calls of `setup()` and `getMessageCount()`), a request fails at once without
  being published, and once a slot is free requests work again on the same connection. 2.10.0 sent each later
  request with a reply subject nobody held, so every one waited out `request_timeout`, even after a slot freed
  up, and 2.10.1 and 2.10.2 dialled again for each and published it before the server rejected the new inbox,
  so a `send()` that failed had stored its message. The first request on a new connection is still published
  before its inbox is confirmed. Two narrow cases get worse than with 2.10.1 and 2.10.2, which closed the
  connection at the `-ERR`. A pull whose own subscription the limit rejects still fails, but now on a
  connection the transport keeps, so while the reply inbox holds the slot the pull needs every pull fails
  until one frees up, where the next pull used to run on a new connection that had no reply inbox yet; a
  `messenger:consume` worker stops at the first failed pull either way. And when the server closes the
  connection right after `maximum subscriptions exceeded`, as it does when an account's limit is lowered, the
  check after the failed operation still moves to a new connection, but with `ping_after_idle: 0`, or when the
  client's heartbeat read the `-ERR`, operations go on to the closed connection until one fails, where they
  used to run on a new one. An acknowledgement sent without waiting for a reply is lost there, and JetStream
  redelivers the message after `ack_wait`; a `send()` or a pull fails, and a failed pull stops a
  `messenger:consume` worker. The operation after the one that failed dials again. An `INFO` that is valid
  JSON but not an object, which no real server sends, now fails the connect with
  `INFO payload is not a JSON object` and is skipped once connected, where a number failed with a `TypeError`
  and an array put default server info in place. The other fixes in 2.10.3 concern reconnects, connection and
  error listeners, `drain()` and services, which the transport does not use: it runs the client with reconnect
  off and closes it with `disconnect()`. One of them also covers the new `connect()` the transport makes when
  it dials again, after a close that came while the reply inbox was being subscribed, but that went wrong only
  when the subscription's write completed after the socket had closed, and the client's own transports fail
  such a write.
- **The functional suite's TLS certificates are generated, not committed (#9).** The repository held the
  private keys of the test CA, server and client certificates, which security scanners report as a leaked
  secret even though they were test-only. `tests/nats/certs/generate.sh` now creates the set with `openssl`
  on each machine, `composer nats:start` (and the Behat context, when it starts NATS itself) runs it first,
  and the files are ignored by git. The keys stay in the history, but nothing uses them any more. A set
  that is about to expire is replaced as well, so that a long-lived checkout does not run the TLS
  scenarios on expired certificates.

## [5.4.0] - 2026-10-04

A **minor** release. It adds duplicate protection, the `deduplicate` option and `DeduplicationIdStamp`
(#53), and makes `getMessageCount()` give up after one connection attempt when NATS cannot be reached (#54).
Nothing changes unless the new option or the new stamp is used.

### Added
- **Duplicate protection: the `deduplicate` option and `DeduplicationIdStamp` (#53).** A `send()` that timed
  out may still have stored the message, so dispatching it again stored it twice, and so did Symfony when a
  worker stopped after a retry or failure-transport send that timed out and NATS redelivered the original.
  The transport now publishes the id of a `DeduplicationIdStamp` as `Nats-Msg-Id`, followed by Symfony's
  retry count and a marker on the failure-transport copy, so JetStream drops a copy sent again within
  `stream_duplicate_window` while each retry stays a message of its own. With `deduplicate: true` every
  message gets an id on its first send, which travels with it; an application adds its own stamp to cover a
  message it dispatches again as a new envelope. Off by default, so nothing changes unless it is set or a
  stamp is added.

### Fixed
- **`getMessageCount()` against a server that cannot be reached tries to connect once, not twice (#54).**
  When the consumer lookup failed, the stream-level fallback dialled again, so `messenger:stats` against a
  server that was down waited 12 seconds for a refused connection and 18 seconds for one that did not
  answer, with the default `connection_timeout`. It now gives up after the first attempt; a lookup that
  failed on an open connection still falls back. It still returns 0 in that case, as documented: the
  README now says that an outage reads as an empty queue there.

## [5.3.0] - 2026-10-04

A **minor** release. It adds the `request_timeout` option (#51), requires `idct/php-nats-jetstream-client`
`^2.10` (#52), and corrects several places in the documentation (#55). Existing configurations behave as
before: `request_timeout` defaults to the 10 seconds every request has waited until now.

### Added
- **`request_timeout` option (#51).** How long an operation waits for the server's reply was fixed at the
  client's default of 10 seconds, and no option changed it: the publish acknowledgement of `send()`, an ACK
  with `ack_sync`, and the JetStream API calls of `setup()` and `getMessageCount()` (`connection_timeout`
  covers only the dial, and `max_batch_timeout` only the pull). `request_timeout` sets it, in seconds, from
  the DSN query or the transport options. It defaults to 10, so nothing changes unless it is set.

### Changed
- **Requires `idct/php-nats-jetstream-client` `^2.10` instead of `^2.4` (#52).** The older releases still
  passed the unit tests, but later ones fixed bugs on paths this transport uses: 2.5.2 the reconnect-disabled
  path a dropped connection takes, 2.5.3 a publish acknowledgement with neither an error nor a stream that
  was accepted as success, 2.9.0 one timeout for the whole request, and 2.10.0 a pull after an unnoticed
  server restart, which now fails as a lost connection and leaves the client Closed, so the re-dial added
  in 5.2.0 sees it at once. CI ran the functional suite against the client's unreleased `dev-main`; it now
  runs it, with the unit tests, against the lowest client release allowed (on PHP 8.2) and the latest one
  (on PHP 8.5).

### Fixed
- **Documentation corrections from the 5.1.0 review (#55).** `get()`'s docblock, a comment, `CLAUDE.md` and
  `STRUCTURE.md` said that a missing consumer reads as an empty pull (status 404); a missing consumer
  reports 503, which only `auto_setup` recovers from, and otherwise `get()` throws. `STRUCTURE.md` described
  a `send()` path through `requestWithHeaders()` that no longer exists. The README said a lower
  `connection_timeout` fails faster on any connection problem; a refused connection takes about 6 seconds
  whatever the value, since a failing dial is tried three times. An old changelog entry said `close()` runs
  on worker shutdown; Symfony never calls it. `send()`'s docblock now lists `ConnectionException` and
  `TimeoutException`. Two stray fragments in the README were moved or removed.

## [5.2.3] - 2026-10-03

A **patch** release with a security fix: a `tls_verify_peer` value the transport does not recognize no
longer turns TLS peer verification off (#42). The README now also says that Symfony's retry delays need
`scheduled_messages` (#50). The public PHP API and the requirements are unchanged.

### Fixed
- **The README now says that Symfony's retry delays need `scheduled_messages: true` (#50).** Symfony's
  retry strategy waits between attempts by re-sending the failed message with a `DelayStamp`, which the
  transport applies only when `scheduled_messages` is enabled (NATS 2.12 or later). With the defaults every
  retry ran straight after the failure, and only the scheduled-messages section of the README mentioned that
  a `DelayStamp` is ignored. The retry section now says so too, and names the alternative for older servers:
  `retry_handler: nats` with `nak_delay` or `backoff`, `max_deliver`, and `max_retries: 0`.

### Security
- **An unrecognized `tls_verify_peer` value no longer turns TLS peer verification off (#42).** It is the
  only boolean option that defaults to `true`, but it went through the same coercion as the others, which
  turns any value outside `1`, `true`, `yes` and `on` into `false`. So `tls_verify_peer=enabled`, `y`, an
  empty value (`?tls_verify_peer=`, easy to get from an unset environment variable in a templated DSN) or a
  `null` option made the transport connect over TLS without verifying the server's certificate, and said
  nothing. Now only `false`, `0`, `no` or `off` turns verification off, and any other value keeps it on. A
  transport configured with such a value whose server certificate cannot be verified now fails the TLS
  handshake instead of connecting unverified; set `tls_verify_peer: false` if that is really intended.

## [5.2.2] - 2026-10-03

A **patch** release with one fix: `messenger:consume --keepalive` no longer stops the worker, or fails the
message it is handling, at the first alarm (#48). The README has a new section on `--keepalive`. The public
PHP API and the requirements are unchanged.

### Fixed
- **`messenger:consume --keepalive` no longer breaks the worker at the first alarm (#48).** Symfony calls
  `keepalive()` from its `SIGALRM` signal handler, and `keepalive()` waited there for the in-progress
  acknowledgement, which PHP does not allow inside a signal handler: it failed with "Cannot switch fibers in
  current execution context". Depending on where the alarm caught the handler, the worker died, or the
  message it was handling failed with that error and was retried. `keepalive()` now queues the
  acknowledgement and returns without waiting. It goes out the next time the event loop runs: at once when
  the handler waits on asynchronous work, such as a call to NATS, but only after the handler returns when it
  only blocks, so such a handler needs an `ack_wait` longer than its longest run. Without a connection,
  `keepalive()` now sends nothing instead of dialling, and a failed acknowledgement no longer surfaces from
  it. The README has a new section on `--keepalive`.

## [5.2.1] - 2026-10-03

A **patch** release with two fixes to how a failed message is retried: the recommended `IgbinarySerializer`
no longer stops the worker when Symfony retries a message or sends it to the failure transport (#46), and
with `retry_handler: nats` Symfony's retry no longer multiplies the deliveries (#47). The public PHP API and
the requirements are unchanged.

### Fixed
- **Retrying a failed message no longer crashes the worker when the transport uses `IgbinarySerializer`
  (#46).** `AbstractEnveloperSerializer::encode()` serialized the whole envelope, including the stamps
  Symfony marks as not to be sent (`NonSendableStampInterface`). One of them, the `AckStamp` a worker adds
  to every message it handles, holds a closure, which igbinary cannot serialize, so every re-send of a
  received message - Symfony's retry, and the send to a NATS failure transport - threw "Serialization of
  'Closure' is not allowed". The exception stopped the worker before it rejected the message, JetStream
  redelivered it after `ack_wait`, and the next worker failed the same way, running the handler again each
  time. `encode()` now leaves those stamps out before serializing, as Symfony's own serializers do, for
  every serializer extending `AbstractEnveloperSerializer`.
- **With `retry_handler: nats`, Symfony's retry no longer multiplies the deliveries (#47).** Symfony's retry
  listener runs for every transport with a retry strategy, and FrameworkBundle gives each one a strategy
  (`max_retries: 3` by default). It re-sent a failed message as a copy while the transport NAKed the original
  for NATS to redeliver, so each failure was retried twice, and every copy did the same again: one message
  that kept failing ran its handler 120 times with `max_deliver: 3`. In nats mode the transport no longer
  publishes that copy, so Symfony's retry strategy (`max_retries`, `delay`, `multiplier`) is ignored and
  NATS redelivers alone, bounded by `max_deliver`. The README now explains how nats mode and Symfony's retry
  and failure transport combine.

## [5.2.0] - 2026-10-03

A **minor** release. It fixes a transport instance that, once its connection was lost, failed every later
operation until the process restarted (#49), and adds the `ping_after_idle` option that checks a connection
which sat idle, or just failed an operation, before it is used. The public PHP API is additive only: a new
`TransportOption::PING_AFTER_IDLE` case and the `pingAfterIdleSeconds()` and `connectionTimeoutSeconds()`
accessors on `NatsTransportConfiguration`. Nothing released in 5.1.0 was renamed or removed, and the PHP
(`^8.2`), Symfony (`^7.2 || ^8`) and `idct/php-nats-jetstream-client` (`^2.4`) requirements are
unchanged; client 2.10.1, released alongside, fixes the client side of the same problem and is recommended.

### Added
- **`ping_after_idle` option (seconds, default `30`; `0` turns it off) (#49).** An operation that finds
  the connection unused for longer than this first checks it with one PING, answered within
  `connection_timeout`, and runs on a new connection if the server does not answer. A server drops a
  client that stops answering its pings, which a PHP process does whenever it is outside a transport call,
  and load balancers and NAT gateways drop idle connections too, none of which the client notices until it
  writes: without the check, the first message after a quiet period was the one that failed. The same
  check follows an operation that failed on the connection other than with a JetStream reply: the client
  can keep reporting a dead connection Open (clients before 2.10 after a failed write, 2.10 after the
  server's fatal `-ERR`), which a re-dial waiting for Closed would never notice, and a connection that
  silently stopped delivering only times out. `keepalive()` is never checked, since Symfony calls it from
  a signal handler. `NatsTransportConfiguration` gains `pingAfterIdleSeconds()` and
  `connectionTimeoutSeconds()`.

### Fixed
- **A lost connection is re-established by the next operation instead of failing every operation until
  the process restarts (#49).** The client runs with reconnect off, so once a connection is lost - the
  server restarted, the network dropped it, or the server closed it because the client stopped answering
  its pings, which a PHP process idle for a few minutes between requests does - the client stays in its
  terminal Closed state and refuses every request. The transport only dialled when it had never connected,
  and nothing in Symfony calls `close()`, so a process that sends for longer than one request (a web app in
  worker mode, a daemon, a handler that dispatches) failed every operation from then on. A Closed client is
  now dialled again by the next operation; the operation that ran into the lost connection still fails.
  With `auto_setup`, the new connection is verified in the same call that dials it rather than the next
  one, and a dial that fails surfaces as the client's `ConnectionException` instead of being wrapped as
  "Failed to setup NATS stream", the first dial included.

## [5.1.0] - 2026-08-09

A **minor** release. It adds fifteen new DSN options and the `auto_setup` provisioning mode, and fixes
several ways the stream and consumer update paths could damage or fail against an existing deployment.
The public PHP API is additive only: new `TransportOption` cases, new accessors on
`NatsTransportConfiguration`, a new `StreamCompression` enum and a new `TypeCoercion::boolValue()`.
Nothing released in 5.0.0 was renamed or removed, and the PHP (`^8.2`), Symfony (`^7.2 || ^8`) and
`idct/php-nats-jetstream-client` (`^2.4`) requirements are unchanged.

### Added
- **New stream configuration DSN options** exposing JetStream stream settings the underlying client
  already supports but the transport did not surface: `stream_retention` (`limits`|`interest`|`workqueue`),
  `stream_discard` (`old`|`new`), `stream_duplicate_window` (seconds), `stream_max_message_size` (bytes),
  `stream_max_consumers`, `stream_compression` (`none`|`s2`), `stream_description`, and the access-policy
  flags `stream_deny_delete`, `stream_deny_purge`, `stream_allow_direct`, `stream_allow_rollup_headers`.
  Enum-backed options are validated with clear errors (like `retry_handler`); numeric options reuse the
  existing validators and require a positive integer, since `null` already means unlimited.
- **New consumer configuration DSN options**: `max_ack_pending`, `inactive_threshold` (seconds), and
  `replay_policy` (`instant`|`original`).
- **`auto_setup` option**, named after the Symfony AMQP transport's option of the same name. When
  enabled, the transport provisions the stream and consumer once, lazily, on the first `send()`/`get()`
  instead of requiring `messenger:setup-transports`. Unlike the AMQP transport, it defaults to `false`,
  which preserves existing behavior.
- **`stream_retention` is written only at stream creation.** NATS rejects changing the retention policy
  of an existing stream, so on the update path the transport preserves the live server value and a
  changed `stream_retention` is ignored until the stream is recreated. (`stream_storage` already behaved
  this way before this release.)
- **Build-time validation that `stream_duplicate_window` does not exceed a finite `stream_max_age`**,
  with a clear error instead of an opaque server rejection at setup time.
- **Consistent naming for the new stream accessors on `NatsTransportConfiguration`.** The stream-scoped
  getters carry the `stream` prefix every other stream accessor on the class already uses:
  `streamRetention()`, `streamDiscard()`, `streamDuplicateWindowSeconds()`, `streamCompression()`,
  `streamDenyDelete()`, `streamDenyPurge()`, `streamAllowDirect()` and `streamAllowRollupHeaders()`.
  The consumer-scoped getters (`maxAckPending()`, `inactiveThresholdMs()`, `replayPolicy()`) keep their
  unprefixed names, matching `consumer()`, `ackWaitMs()` and `maxDeliver()`. None of these names have
  been released.
- **`StreamCompression` enum** (`none`|`s2`) backing the `stream_compression` option, so the allowed
  values have one authoritative definition instead of an allowlist repeated in the validator, the
  accessor docblock and the README. `NatsTransportConfiguration::streamCompression()` returns the enum,
  matching how `stream_retention`, `stream_discard` and `replay_policy` are exposed.
- **`TypeCoercion::boolValue()`** centralizing the mixed to bool casting policy previously inlined in the
  configuration builder.

### Fixed
- **Tightened validation of the new numeric options.** `stream_max_message_size` and
  `stream_duplicate_window` now require a positive integer instead of a non-negative one: they default
  to `null`, which already means "unlimited" / "server default", so an explicit `0` only looked like the
  option had been ignored (NATS reads `max_msg_size: 0` as unlimited and replaces a `0` duplicate window
  with its own 2-minute default). `stream_max_message_size` is additionally capped at `2147483647`,
  since the server stores it as a 32-bit integer and a larger value failed inside `setup()` with a raw
  Go unmarshal error. `stream_description` is checked against the server's 4096-character limit.
- **The stream update path clamps an inherited de-duplication window to a lowered `stream_max_age`.**
  NATS rejects a stream whose `duplicate_window` exceeds a finite `max_age`, and the window is normally
  inherited from the live stream, so lowering `stream_max_age` on a stream sitting on the server's
  2-minute default window failed at setup with "duplicates window can not be larger then max age". The
  build-time check cannot catch this, since it only compares options the caller supplied. The update
  payload now clamps the window the same way the server does when a stream is created.
- **`auto_setup` now re-provisions when JetStream reports the stream or consumer as missing.** The
  one-shot flag was latched for the lifetime of the transport object, so a consumer that NATS removed
  after `inactive_threshold` left the worker unable to pull. A missing resource now triggers one
  re-provisioning attempt and a single retry, and `close()` clears the flag so a reopened connection
  verifies provisioning again. The statuses that count as "missing" are 404, 409 and **503**: a deleted
  durable leaves nothing subscribed to answer the pull, which surfaces as 503, not 404. Without
  `auto_setup` the historical contract is unchanged: 404 and 408 read as an empty queue and everything
  else propagates.
  Note that a replacement consumer starts from `deliver_policy=all`, so it redelivers everything the
  stream still retains, including acknowledged messages under the default `limits` retention. See the
  warning in the README: this is inherent to losing a durable consumer, not to `auto_setup`.
- **`stream_deny_delete` and `stream_deny_purge` are never cancelled on an existing stream.** NATS can
  turn both on but never off, so a stream that already denies deletes or purges would fail every
  `setup()` once the option was present as `false` - which is the value the README itself shows. The
  update path now keeps the server's value for those two. `stream_allow_direct` and
  `stream_allow_rollup_headers` were measured to be freely mutable and stay changeable.
- **The four tri-state stream flags reject unrecognized values.** `stream_deny_delete=maybe` used to
  coerce to `false` and be written to the server as a deliberate instruction; it is now a configuration
  error, consistent with the enum-backed options.
- **`replay_policy` no longer breaks `setup()` on an existing durable consumer.** NATS refuses to change
  a consumer's replay policy, in both directions, so adding `replay_policy` to the DSN of a running
  deployment made every `setup()` (and, with `auto_setup`, every `send()`/`get()`) fail with "replay
  policy can not be updated", unrecoverably. Removing the option again did not help: the omitted field
  reads as a change back to the server default and is rejected just the same. The transport now looks
  the consumer up and always writes its existing replay policy, applying the configured value only to a
  consumer that does not exist yet.
- **`stream_max_consumers` and `stream_max_message_size` no longer clobber an existing stream.** The
  update payload used to write `max_consumers` and `max_msg_size` unconditionally, falling back to the
  unlimited sentinel `-1` when the options were unset. Neither field was written at all before these
  options existed, so both are fields an operator may have set out of band on a live stream. For
  `max_consumers` the consequence was severe: NATS up to and including 2.11 rejects any change to it, so
  `messenger:setup-transports` failed permanently for anyone whose stream had a consumer limit, and on
  2.12 and newer the limit was silently cleared. `max_msg_size` is mutable everywhere, so it failed
  quietly instead: a stream capped at 1 MiB would be reset to unlimited on the next `setup()` with
  nothing in the output to say so. Both are now written only when the corresponding option is
  configured; otherwise the live server value is preserved.

## [5.0.0] - 2026-06-17

This is a **major** release. It is backward-incompatible for two reasons even though the transport's own
PHP API is largely additive: the required `idct/php-nats-jetstream-client` constraint moves from `^1` to
`^2.4` (a major dependency upgrade), and the internal `TypeCoercionTrait` was replaced by a `TypeCoercion`
final class. There are also two minor validation/behavior changes (see Fixed): `stream_max_age` now
rejects fractional values, and DSN/option credentials are no longer trimmed. PHP (`^8.2`) and Symfony
(`^7.2 || ^8`) requirements are unchanged from 4.0.0.

### Changed
- **Readability: removed two small internal duplications (BC-safe, no behavior change)** - the
  `{topic}.delayed.>` wildcard subject now has a single `delayedSubjectPattern()` definition shared by
  the add ({@see buildDesiredSubjects}) and drop ({@see buildUpdatedStreamConfiguration}) sides so they
  cannot drift, and `AbstractEnveloperSerializer::decode()` folds its missing-`body` guard into the
  null/non-string/empty check via `?? null` (one gate instead of two with the same message). A deep
  readability review (with adversarial verification) considered several other extractions and rejected
  them as taste-driven churn in this deliberately-verbose, multiply-reviewed code.
- **Collapsed redundant double-guards in DSN/option validation** - `toNumber()` now gates solely on
  `is_numeric()` (which already rejects every non-numeric type), and `parseDsn()` validates the
  `parse_url()` result and the missing host in a single throw. The previous split guards threw the same
  message for the same inputs, so each masked the other - leaving undetectable (equivalent) mutants.
  No behavior change; mutation Covered MSI rises from ~99% to 100%.
- **NATS client upgraded to `idct/php-nats-jetstream-client` `^2.4`** (from `^1`). The v2 client is a
  major release (Object Store / Services / custom-transport breaking changes) but none of those touch
  this bridge's API surface; every method this transport uses changed only by gaining optional trailing
  parameters. The unit suite and PHPStan (level max) stay green.
- **Unified message publishing** - `send()` now publishes both plain and header-carrying (including
  scheduled/delayed) messages through `JetStreamContext::publish()` instead of dropping to the low-level
  `requestWithHeaders()` for header messages. Header/scheduled publishes therefore gain the client's
  built-in transient-503 ("no responders") retry and consistent `JetStreamException` error reporting.
  The hand-rolled `assertJetStreamPublishSucceeded()` validator was removed.
- **`getMessageCount()`** now returns `num_ack_pending + num_pending` (in-flight **plus** waiting)
  instead of `max(...)`, which undercounted whenever both coexisted.
- **`declare(strict_types=1)`** is now declared in every `src/` file.
- **`TypeCoercionTrait` replaced by a `final class TypeCoercion`** with pure `public static`
  `intValue()` / `floatValue()` / `stringValue()` helpers. The mixed→scalar coercion policy is now a
  standalone, independently testable unit (own unit tests plus a functional DSN-coercion scenario)
  instead of a trait mixed into three classes. No behavior change.
- **Deterministic existing-stream detection in `setup()`** - when stream creation fails, the transport
  now queries JetStream stream info (`404` ⇒ absent) to decide whether to update, instead of matching
  server-specific `"already in use"` / `"already exists"` error strings. This removes the brittle
  message parsing and collapses the previous two stream-info lookups into one (the fetched config is
  reused for the update).
- **Typed stream/consumer configuration in `setup()`** - the stream and durable consumer are now built
  with the v2 client's fluent `StreamConfiguration` / `ConsumerConfiguration` builders and created via
  `addStream()` / `addConsumer()`, replacing hand-assembled option arrays. A single `StreamConfiguration`
  is the source for both the create and update paths (the latter via `toArray()`), and `maxAge()` handles
  the seconds→nanoseconds conversion. No behavior change.

### Added
- **Daily scheduled mutation-testing workflow and Dependabot** - `.github/workflows/mutation.yml` re-runs
  Infection daily (03:17 UTC) and on demand (`workflow_dispatch`) against the default branch, a safety net
  beyond the per-push/PR mutation step in the main CI; it uploads `infection.log` on failure.
  `.github/dependabot.yml` keeps the root and `tests/functional` Composer dependencies and the GitHub
  Actions up to date on a weekly cadence (minor/patch bumps grouped to cut PR noise).
- **Hardened large-message, multi-consumer, and scheduled-message coverage** - new unit tests for
  large (1 MiB) payload round-tripping on both the send and receive paths
  (`testSendPublishesLargePayloadWithoutTruncation`, `testGetDecodesLargePayloadWithoutTruncation`),
  shared-durable-consumer routing (`testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer`),
  and never-early scheduling (`testSendDelayedMessageNeverSchedulesBeforeRequestedDelay`). New Behat
  feature `nats_large_messages.feature` round-trips 128 KB / 64 KB messages through one consumer and
  load-balances them across two, and `nats_delayed.feature` gains a scenario asserting a delayed message
  is not visible to the consumer before its scheduled time. The send command gained a `--size` option.
  Further hardening: a 5-consumer / 100-message shared-consumer load-balancing scenario
  (`nats_consumer.feature`), larger delayed batches and delayed-message load balancing across 3 consumers
  (`nats_delayed.feature`), and a unit test for a far-future (1-hour) delay
  (`testSendDelayedMessageWithLargeDelaySchedulesFarInTheFuture`). The CI "Run functional tests" step
  timeout was raised from 10 to 25 minutes (and the job cap from 20 to 40) to accommodate the fuller
  matrix; the new delayed scenarios use count-limited consumers so they exit promptly after the delay
  rather than waiting out a fixed time limit.
- **README PHP examples are syntax-checked in CI** - `ReadmeExamplesTest` lints every fenced ` ```php `
  block in the README (and pins their count), so a snippet that stops being valid PHP fails the build.
  This complements the existing tests that exercise the README's DSN, option, and serializer examples.
- **`CloseableTransportInterface` support** - the transport now implements `close()`, which disconnects
  the NATS client and resets the lazy connection state so resources are released on demand (when the
  application calls it: Symfony itself never calls `close()`, not even on worker shutdown). It is a no-op
  when no connection was opened, and the transport reconnects lazily on the next operation.
- **`KeepaliveReceiverInterface` support** - the transport now implements Symfony Messenger's
  `keepalive()`, sending an in-progress (`+WPI`) acknowledgement so a long-running handler resets the
  JetStream redelivery timer instead of losing its message to `ack_wait` expiry. NATS resets to the
  consumer's configured `ack_wait`, so the advisory `$seconds` hint is not forwarded.
- **Mutation testing with Infection** - added `infection/infection` (dev), an `infection.json5` config
  (floors: `minMsi` 90 / `minCoveredMsi` 95), a `composer test:mutation` script, and a CI step. The suite
  currently scores 100% covered MSI with 100% mutation code coverage.
- **Expanded unit coverage (~99.6% statements) and functional coverage for the new features** - added
  unit tests for previously-uncovered branches (non-scalar option coercion, too-short DSN path,
  integer/uppercase boolean coercion, update-path `max_age` conversion, non-array server subjects) and
  Behat scenarios for `ack_sync` (synchronous acknowledgement) and `max_deliver` (NATS bounds redelivery
  of a poison message).
- **NATS-native redelivery tuning for `retry_handler: nats`** - new options `nak_delay` (seconds to
  delay a NAK, default 0), `ack_wait` (seconds before JetStream redelivers an unacked message),
  `max_deliver` (cap on redeliveries; **prevents a poison message redelivering forever**), and `backoff`
  (per-attempt delay schedule in seconds). NAKs use the client's `nakWithDelay()` when a delay is set,
  and the durable consumer is created with the configured `ack_wait` / `max_deliver` / `backoff`. All
  default to the previous behavior (immediate NAK, server-default ack-wait, unlimited redeliveries).
- **Up-front validation that `max_deliver` exceeds the `backoff` length** - when both options are set,
  the builder now rejects a `max_deliver` that is not strictly greater than the number of `backoff`
  entries (NATS requires room for at least one delivery beyond the backoff schedule), turning an opaque
  server-side consumer-creation failure into a clear configuration error.
- **`ack_sync` option (opt-in double-ack)** - when enabled, `ack()` uses the v2 client's `ackSync()` and
  waits for server confirmation of each acknowledgement, so a dropped ACK cannot silently cause
  redelivery. Defaults to `false` (fire-and-forget, lower latency).
- **`CLAUDE.md`, `HUMANS.md`, `STRUCTURE.md`** - agent guidance, human onboarding, and an architecture/
  layout reference, respectively.

### Changed
- **Simplified DSN path validation** (internal) - removed the redundant `MIN_PATH_LENGTH` guard, which
  was fully subsumed by the stream/topic segment check. A path that supplies a stream but no topic
  (e.g. `/a`) now reports the more accurate "must contain both stream name and topic name" error instead
  of "Stream name not provided." The two numeric validators now share a symmetric `$integerOnly = false`
  signature.
- **`stream_max_age` now rejects non-integer values** instead of silently truncating them (e.g. `2.5`
  was accepted and coerced to `2`). The option is integer seconds; fractional values now raise a clear
  validation error, consistent with the other integer-only stream limits.
- **Removed the redundant `floatOption()` accessor** (internal, no behavior change) - its two callers
  now read the option directly through `TypeCoercion::secondsToMs()`, unifying all the seconds→ms
  accessors on one idiom and removing a double-coercion.
- **De-duplicated option conversion logic** (internal, no behavior change) - added
  `TypeCoercion::secondsToMs()` (the single home for the seconds→milliseconds rounding rule, previously
  copy-pasted across five call sites) and a private `nullableIntOption()` accessor in
  `NatsTransportConfiguration` (the four optional stream-limit / `max_deliver` getters now share one
  null-passthrough definition).

### Fixed
- **README accuracy pass** - updated the stale "~99% covered MSI" figure to 100% (matching the badge),
  rewrote the "Publish Response Validation" section to describe the current client-side `publish()` ack
  validation (the old hand-rolled JSON/"header-aware request path" wording was removed long ago), and
  fixed the Quick Start send example to inject `MessageBusInterface` (Symfony does not autowire the
  concrete `MessageBus`). A full audit confirmed every other example, option default, "Tested by"
  method, Behat scenario, and DSN data-provider case in the README still matches the code.
- **Scheduled (delayed) messages are never delivered before the requested delay** (#37) - the
  `DelayStamp` delay maps onto a whole-second NATS `@at` schedule that previously *truncated* the
  sub-second part, so a small delay could fire immediately and any delay could arrive up to ~1s early.
  `send()` now rounds the delivery time **up** to the next whole second, so the message is never
  delivered before the requested delay elapses (at most ~1s late). See the README for the
  second-resolution caveat.
- **A failed NAK/TERM no longer masks the original decode error in `get()`** (#34) - when a delivered
  message fails to deserialize, the transport still rejects it, but a secondary failure while
  acknowledging (e.g. a dropped connection) can no longer replace the decode exception that propagates.
  The decode error is the root cause an operator needs, so it stays the one that escapes `get()`.
- **`setup()` removes an orphaned `{topic}.delayed.>` subject when `scheduled_messages` is disabled**
  (#35) - the stream-update subject merge previously only ever added subjects, so a stream that once had
  scheduling enabled kept the transport-managed delayed subject forever. The update now drops that
  specific subject when scheduling is off (operator-added subjects are preserved), mirroring the
  `allow_msg_schedules=false` clearing.
- **Clarified `getMessageCount()`'s fallback semantics** (#36) - documented that the stream-level
  fallback (used when consumer info is unavailable) is a loose upper bound, not an exact backlog: under
  the default limits retention policy it counts already-acknowledged-but-retained messages. The accurate
  consumer-info path (`num_ack_pending + num_pending`) is unaffected.
- **README/docs accuracy** - corrected the Architecture "serialization (igbinary)" bullet to describe the
  pluggable `SerializerInterface`, listed the full set of implemented Messenger interfaces, fixed the
  `docs/TESTS.md` mutation thresholds (95/98 -> 90/95), and refreshed the test count and coverage badge.
- **DSN/option credentials are no longer trimmed** - leading or trailing whitespace in a username or
  password is significant and was previously stripped (corrupting the credential). Credential resolution
  now maps only null/empty to null without trimming; TLS file paths are still trimmed.
- **Disabling `scheduled_messages` clears `allow_msg_schedules` on an existing stream** - the `setup()`
  update path now writes the flag explicitly (false when disabling) instead of preserving the server's
  previous `true`. The field is still omitted entirely when scheduling is off and the stream never had
  it, so nothing unknown is sent to a server older than NATS 2.12.
- **`setup()` no longer silently downscales an existing stream's replica count** - on the update path
  the transport now preserves the server's `num_replicas` unless `stream_replicas` is explicitly
  configured (mirroring the existing `storage` preservation). Previously a stream created with, say,
  3 replicas in a cluster was reset to 1 whenever `setup()` ran without the option set, silently
  eliminating its high-availability/durability.
- **DSN credentials are decoded with `rawurldecode()` instead of `urldecode()`** - a literal `+` in a
  username/password supplied via the DSN was being turned into a space (form-encoding semantics),
  corrupting the credential and breaking authentication. Percent-escapes (`%40` → `@`, `%2B` → `+`)
  still decode correctly, now matching RFC 3986 userinfo semantics and the underlying NATS client.
- **README handler example no longer references a removed interface** - the "Handle Messages" example
  used `Symfony\Component\Messenger\Handler\MessageHandlerInterface`, removed in Symfony 7.0 (the
  package requires `^7.2 || ^8.0`), so copying it caused a fatal "interface not found". It now uses
  the `#[AsMessageHandler]` attribute.
- **`get()` LogicException message** now names the actual stamp (`TransportMessageIdStamp`) instead of
  the unrelated `ReceivedStamp`; the README `connection_timeout` docs now state it sets the connection
  (dial) timeout, not a per-operation socket I/O timeout.
- **README/comment accuracy (review sweep)** - corrected three stale `Tested by:` references in the
  README that survived the earlier docs sweep (`testSendUsesRequestWithHeadersWhenHeadersArePresent` →
  `…UsesPublish…`; two `testGetMessageCountReturnsAckPendingWhenHigherThanPending` → `…SumsAckPendingAndPending`),
  the Infection threshold paragraph (95/98 → 90/95) and the deprecated `composer install --dev` command;
  reworded the `NatsTransportFactory` class docblock (the igbinary auto-default never triggers via the
  factory, only on direct construction) and the `get()` docblock ("HTTP 404/408" → "JetStream status 404/408").
- **Empty-payload messages no longer poison-loop** - `get()` now sends TERM for a delivered message
  with an empty payload (which can never decode into an envelope) instead of silently skipping it.
  Skipping left the message unacknowledged, so JetStream redelivered it every `ack_wait` indefinitely;
  TERM stops redelivery regardless of the configured retry handler. A message without a reply (ack)
  subject is still skipped, since it cannot be acknowledged at all.
- **README serializer default clarified** - documented that under the Symfony framework the transport
  factory always receives Symfony's resolved serializer (the framework default is the native
  `PhpSerializer`), so the transport's built-in igbinary auto-selection only applies to direct
  instantiation; to use igbinary under the framework, set the transport's `serializer:` key explicitly.
- **Actionable error for `scheduled_messages` on NATS < 2.12** - when the connected server is too old
  for `allow_msg_schedules`, `setup()` now catches the client's typed `UnsupportedFeatureException` and
  fails with a clear message ("The 'scheduled_messages' option requires NATS Server >= 2.12, but the
  connected server reports …. Disable scheduled_messages or upgrade NATS.") instead of a generic wrapped
  error.
- **Publish acknowledgements always fail closed** - the previous header-publish path silently accepted
  an empty/non-JSON JetStream ack; publishing through `JetStreamContext::publish()` rejects empty,
  malformed, or error acks consistently for all messages.
- **`get()` skips messages without a reply (ack) subject** instead of yielding an envelope with an
  unusable transport message id that would later fail at ack/reject time.
- **`setup()` can now relax or clear stream limits** - on update, unset `stream_max_age` /
  `stream_max_bytes` / `stream_max_messages` / `stream_max_messages_per_subject` options reset to
  JetStream's "unlimited" sentinels instead of preserving the previous server-side value.
- **`getMessageCount()` catches `\Throwable`** (not just `\Exception`), honouring its documented
  "returns 0 if both lookups fail" contract for `\Error`-type failures surfaced by awaited futures.
- **README accuracy** - corrected the coverage badge (`95.97%` → `99.56%`) and the test-count claim
  (`102` → `248` unit tests), and removed a non-existent `delay` option from the Multi-Subject Streams
  example (there is no `delay` transport option; the value was silently ignored).
- **Documentation** - refreshed the stale `tests/functional/README.md` (removed dead benchmark-doc
  links and replaced the outdated "three scenarios" list with the full feature-file table) and removed
  the non-existent `delay` option from the builder tests and `docs/TESTS.md`.
- **Test suite** - migrated the last doc-comment `@dataProvider` to the `#[DataProvider]` attribute
  (PHPUnit 12-ready), hardened the Behat consumed-message check to use the deterministic marker-file
  count as the primary signal, and added unit coverage for the lazy-connect path.

### Security
- **PhpSerializer fallback now warns loudly** - when `ext-igbinary` is missing and no serializer is
  configured, the transport emits an `E_USER_WARNING` (previously a quiet `E_USER_NOTICE`) explaining
  that the `PhpSerializer` fallback uses native `unserialize()` and carries the same object-injection
  risk as igbinary. The README security section now documents this explicitly.

## [4.0.0]

### Added
- **IDCT NATS JetStream Client** - Replaced `basis-company/nats` with `idct/php-nats-jetstream-client` (`^1`) for amphp-based coroutine support, active maintenance, and access to newer NATS features.
- **Configurable retry handler** - New `retry_handler` option (`symfony` or `nats`) controls failure behavior. `symfony` (default) sends TERM; `nats` sends NAK for NATS-managed redelivery.
- **Scheduled / delayed messages** - `scheduled_messages` option enables Symfony `DelayStamp` support via NATS scheduled message headers (`Nats-Schedule`, `Nats-Schedule-Target`). Requires NATS >= 2.12.
- **Multi-subject streams** - Multiple transports can share a single NATS stream with different subjects. Setup merges subjects without duplicating or overwriting existing ones.
- **Stream storage backend** - `stream_storage` option (`file` or `memory`) controls JetStream stream storage type. Existing streams preserve their original storage backend on update.
- **Stream max messages** - `stream_max_messages` option limits total messages stored in the stream (maps to NATS `max_msgs`).
- **Stream max messages per subject** - `stream_max_messages_per_subject` option limits messages retained per individual subject (maps to NATS `max_msgs_per_subject`).
- **Stream max bytes** - `stream_max_bytes` option limits total storage size of the stream.
- **TLS and mTLS support** - Full TLS configuration options including `tls_required`, `tls_handshake_first`, `tls_ca_file`, `tls_cert_file`, `tls_key_file`, `tls_key_passphrase`, `tls_peer_name`, and `tls_verify_peer`.
- **Publish response validation** - JetStream publish acknowledgements are parsed and validated; protocol errors fail closed instead of being silently accepted.
- **Stream-exists detection hardening** - Setup prefers explicit NATS conflict messages for existing-stream detection; ambiguous 400 responses trigger a stream-existence verification before updating.
- **Comprehensive functional test suite** - Behat-based functional tests covering message flow, batching, TLS, mTLS, NAK/TERM retry handlers, delayed messages, stream limits, multi-subject streams, and consumer strategies.
- **PHPStan level max** - Static analysis at maximum strictness level.
- **Edge case test coverage** - Added tests for: decode failure with NAK handler, multiple message batching, consumer creation errors, TLS DSN constructor, negative delay stamps, stream update failures, batching config flow-through, partial batch consumption, stream eviction enforcement, consumer name verification via JetStream API.
- **Builder validation tests** - Added tests for: negative batching, non-integer batching float, negative connection timeout, non-numeric connection timeout, zero/negative/non-numeric max_batch_timeout, negative/non-integer stream_replicas, non-numeric stream_max_age, array batching, malformed DSN, missing host DSN, dotted topic names, connection timeout propagation to NatsClient.
- **Factory DSN edge cases** - Added tests for: default port parsing, no-auth DSN, query parameter parsing, HTTP scheme rejection.

### Changed
- **Default failure behavior** - `reject()` now sends TERM (previously ACK in v3). This is a **breaking change**; use `retry_handler: nats` to restore NAK-based redelivery.
- **PHP requirement** - Minimum PHP version raised to 8.2.
- **PHPUnit** - Upgraded to PHPUnit 11.
- **Symfony compatibility** - Supports Symfony ^7.2 and ^8.0.

### Fixed
- **`stream_max_messages` not applied** - The option was previously ignored during stream creation; now correctly maps to NATS `max_msgs`.

## [3.x] - Previous releases

Initial Symfony Messenger NATS JetStream bridge using `basis-company/nats` client library.
