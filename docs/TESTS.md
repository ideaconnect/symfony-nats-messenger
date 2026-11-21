# Test Coverage Map

This document maps each feature of the Symfony NATS Messenger Bridge to the tests that cover it.

## Unit Tests

### Transport Core (`tests/unit/NatsTransportTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing & validation** | `testConstructorWithValidDsnInitializesTransport`, `testConstructorWithDottedTopicInitializesTransport`, `testConstructorWithInvalidDsnThrowsException`, `testConstructorWithoutPathThrowsException`, `testConstructorWithoutTopicThrowsException`, `testConstructorWithWildcardTopicThrowsException` |
| **Message sending** | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, `testSendSerializationFailureUsesErrorDetailsStampMessage`, `testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp` (and publishes nothing), `testSendPublishesLargePayloadWithoutTruncation`, `testSendSerializationFailureKeepsTheSerializerErrorAsThePreviousException` |
| **Publish error handling** | `testSendThrowsWhenJetStreamHeaderPublishReturnsError` (publish ack parsing/validation is delegated to the client's `JetStreamContext::publish()`, surfaced as a `JetStreamException`) |
| **Message receiving** | `testGetReturnsDecodedEnvelopeWithHeadersAndMessageId`, `testGetLeavesOutHeadersWhoseNameIsAnInteger` (a header named `1` or `-5` arrives as an int key and is not passed to `decode()`, which takes string names, so a serializer that reads the names as strings under strict types decodes the message), `testGetTermsEmptyPayloadMessagesToStopRedelivery`, `testGetSkipsMessagesWithoutReplySubject`, `testGetReturnsEmptyArrayWhenThePullReports404`, `testGetReturnsEmptyArrayWhenBatchRequestTimesOut`, `testGetRethrowsUnexpectedJetStreamExceptions`, `testGetWithMultipleValidMessagesReturnsAll`, `testGetWithBatchingConfigPassesBatchSizeToFetchBatch`, `testGetDecodesLargePayloadWithoutTruncation`, `testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer`, `testGetDeliversTheRestOfTheBatchAfterAMessageWithoutAReplySubject` |
| **ACK / reject** | `testFindReceivedStampReturnsTransportStamp`, `testAckWithoutTransportStampThrowsException`, `testAckAcknowledgesReceivedEnvelope`, `testAckUsesAckSyncWhenEnabled`, `testRejectWithoutTransportStampThrowsException`, `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats` |
| **Keepalive (`messenger:consume --keepalive`)** | `testKeepaliveSendsInProgressForReplyToken`, `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting` (needs the pcntl and posix extensions), `testKeepaliveDoesNotWaitForTheAcknowledgement`, `testKeepaliveWithoutAConnectionSendsNothingAndDoesNotDial`, `testKeepaliveWhoseAcknowledgementFailsRaisesNoUnhandledError`, `testKeepaliveWithoutTransportStampThrowsException`, `testKeepaliveNeitherPingsNorDials` |
| **Retry handler (TERM / NAK)** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseTermTransportPath`, `testHandleFailedDeliveryUsesBaseNakTransportPath`, `testConstructorWithInvalidRetryHandlerThrowsException` |
| **NATS retry handler ignores Symfony's retry strategy** | `testNatsModeDoesNotPublishTheCopySymfonysRetrySends`, `testSymfonyModePublishesTheCopySymfonysRetrySends`, `testNatsModeStillPublishesWhatIsNotSymfonysRetryCopy` (failure-transport copy, message not received here, plain dispatch), `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy` |
| **A delivery sent for retry in nats mode is NAKed, whether the worker acknowledges it (Messenger 7.4.22, 8.1.10 and later) or rejects it (#90)** | `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy` and `testWorkerInNatsModeNaksOnlyTheDeliveryItSentForRetry` (through Symfony's Worker; they fail on 7.4.22 / 8.1.10 without the fix), `testAckNaksADeliveryWhoseRetryCopyNatsModeLeftOut` (defaults, `nak_delay`, `ack_sync`), `testADeliverySentForRetryIsNakedOnce` (settled by `ack()` or `reject()`), `testAckNaksOnlyTheDeliveryTheRetryCopyBelongsTo` (another message, another delivery of the same message), `testEveryDeliverySentForRetryIsNakedWhenSettledLater`, `testSymfonyModeAcksADeliveryItPublishedTheRetryCopyOf` |
| **A transport in nats mode warns once that Symfony's retry strategy is on (#94)** | `testNatsModeWarnsOnceThatSymfonysRetryStrategyIsOn` (through Symfony's Worker with FrameworkBundle's default strategy: two left-out copies, one warning with the exact text), `testEachTransportWarnsOnceAboutItsOwnStreamAndSubject` (once per transport object, copies with and without a delivery token), `testNatsModeWithoutSymfonysRetriesLogsNoWarning` (Worker with `max_retries: 0`), `testNoWarningWhenSendLeavesOutNoRetryCopy` (symfony mode; nats mode: failure-transport copy, message not received in this process, plain dispatch) |
| **`retry_via: nak`: Symfony's retry carried out as a NAK with Symfony's delay (#113, #109)** | `testNakRetryNaksWithTheDelaySymfonyComputed` (the retry copy is left out and the NAK carries the copy's `DelayStamp` delay, whether `ack()` or `reject()` settles the delivery; nothing is logged), `testNakRetryNaksPlainlyForADelayOfZero` (a delay of 0, or a copy without a `DelayStamp`), `testNakRetryNaksWithTheDelayOfARecoverableException`, `testNakRetryCutsADelayThatWouldOverflow` (cut to the longest delay that fits the frame's nanoseconds), `testNakRetryFollowsSymfonysRetryStrategyThroughTheWorker` (Symfony's Worker with its retry and failure-transport listeners over a message that keeps failing: NAKs of 1, 2 and 4 s, `willRetry()` true three times and then false, a TERM and one failure-transport copy, nothing published and nothing logged), `testNakRetryTermsADeliverySymfonyDoesNotRetry`, `testNakRetryTermsAnUnrecoverableFailure`, `testNakRetryNaksAFailureSymfonyRetriesWhoseFirstExceptionIsUnrecoverable` (two handlers, the first unrecoverable: Symfony retries, and symfony mode NAKs the rejected delivery with the delay) |
| **`retry_via: nak`: the retry count is the count the message carries plus its earlier deliveries** | `testGetGivesARedeliveredMessageItsEarlierDeliveriesAsRetryCount` (both forms of the JetStream reply subject; none on a first delivery, with `retry_via: copy` or in nats mode), `testGetAddsTheEarlierDeliveriesToTheRetryCountTheMessageCarries` (data provider: a carried count of 0, 1 and 5 on a third delivery, and a first delivery, which leaves the count as it is; fails on "the higher of the two wins", which PR #113 had) |
| **`retry_via: nak`: a retry no NAK can carry is published as a copy** | `testNakRetryPublishesTheCopyOfTheLastDeliveryMaxDeliverAllows` (data provider: the last delivery settled by `ack()` and by `reject()`, a delivery past a `max_deliver` that was lowered, `max_deliver` 1; nothing is NAKed and nothing is logged), `testNakRetryNaksADeliveryThatIsNotKnownToBeTheLast` (data provider: no `max_deliver`, a delivery token that is no JetStream reply subject, the delivery before the last, and nats mode at `max_deliver`), `testNakRetryRunsSymfonysStrategyToItsEndPastMaxDeliver` (Symfony's Worker with `max_deliver` 2 and `max_retries` 3: four runs, the second delivery's retry as a copy with retry count 2, then a TERM and one failure-transport copy; settled as the installed Symfony Messenger settles a retried message), `testNoWarningWhenSendLeavesOutNoRetryCopy` (the case `retry_via=nak, a retry copy without its delivery token`, which is published) |
| **`retry_via: nak`: the last delivery is the consumer's** | `testNakRetryPublishesTheCopyOfTheConsumersLastDeliveryWhateverTheOptionSays` (data provider: the option unset, the option at 5, on a consumer limited to 3: the third delivery's retry is a copy, with one warning; fails on 5.9.0, which NAKed it), `testNakRetryNaksADeliveryTheConsumerStillRedelivers` (the option at 3 on a consumer limited to 5: a NAK with Symfony's delay, one warning), `testTheConsumersMaxDeliverIsReadAgainOnceItIsAMinuteOld` (three retries at 0, 59.9 and 60 s read the consumer twice), `testAConsumerThatCannotBeReadLeavesTheOptionAndIsNotAskedAgainOnThatConnection` (a refused read leaves the option, one warning, no second read on that connection 120 s later, a read again after `close()`), `testAConsumerUnreadableLaterLeavesTheValueReadBefore` (a read that fails after one that gave 3 leaves 3, not the unset option), `testARetryAfterSetupAsksForTheConsumersLimitAgain` (`setup()` wrote 5, the consumer reports 3 by the first retry: a copy, one warning), `testAConsumerThatReportsMaxDeliverZeroHasNoLimit`, `testAReplacedConnectionAsksForTheConsumerAgain` (the client closes between two retries, the transport dials again, and the consumer is read on the new connection), `testAConsumerWhoseLimitIsNotTheOptionsIsFollowedWithoutALogger` |
| **`scheduled_messages` refused on an interest stream nothing listens on** | `testSetupRefusesScheduledMessagesOnAnInterestStreamNothingHasInterestIn` (on the stream that exists, before it is updated: neither addStream nor updateStream), `testSetupRefusesScheduledMessagesOnAnInterestStreamItWouldCreateWithoutCreatingIt` (a stream that does not exist and would be created with interest retention: refused without addStream or listConsumers; fails on the first form of 5.9.1) (data provider: no consumer, one on the topic, one on a single delayed subject, one on `{topic}.delayed`, one on another topic, filter subjects that miss: a `RuntimeException` naming the ways out, the consumer not created; fails on 5.9.0), `testSetupKeepsScheduledMessagesOnAnInterestStreamWithAConsumerOnTheDelayedSubjects` (data provider: `{topic}.delayed.>`, `{topic}.delayed.*`, `{topic}.>`, `>`, `*.delayed.*`, no filter, filter subjects one of which matches), `testSetupAsksForNoConsumersOnAStreamThatKeepsDelayedMessages` (limits, work-queue: no consumer list, and a pull reads nothing more), `testTheFirstPullRefusesScheduledMessagesOnAnInterestStreamNothingHasInterestIn` (a `TransportException` at each pull, no fetch; fails on 5.9.0), `testTheStreamIsCheckedOnceForAWorkerWhosePullsGoOn`, `testAStreamThatCannotBeReadIsLetThroughWithOneWarning`, `testAStreamThatCannotBeReadIsLetThroughWithoutALogger`, `testWithoutScheduledMessagesAPullChecksNothing` |
| **A message that cannot be decoded is handled as Symfony's own transports handle it (5.10.0), and nats mode TERMs it (5.9.1)** | `testBeforeMessenger81AMessageThatCannotBeDecodedIsTermedAndItsErrorThrown` (data provider: symfony mode, `retry_via: nak`, nats mode; before Symfony Messenger 8.1 `get()` TERMs it and throws the decode error, as Doctrine, Redis and AMQP do, and logs nothing; fails on the 5.10.0 development form, which logged and went on), `testBeforeMessenger81ATermThatFailsLeavesTheDecodeErrorThrown` (a TERM that fails does not replace the decode error), `testAnErrorOfTheSerializerOtherThanADecodingFailurePropagatesWithTheMessageUnsettled` (data provider: before Messenger 8.1, 8.1 and later; another exception propagates, with no TERM, NAK or ACK; fails on 5.9.x, which TERMed it), `testFromMessenger81AMessageThatCannotBeDecodedGoesOnAsTheDecodingFailure` (from 8.1 on, the decoding failure goes on as a `MessageDecodingFailedException` message: what was received, the serializer's exception and code, the delivery token, nothing settled; skipped before 8.1), `testFromMessenger81TheDecodingFailureOfARedeliveryCarriesItsRetryCount` (with `retry_via: nak` it carries the earlier deliveries, so Messenger 8.1.0 to 8.1.10, which retry such a failure, stop at `max_retries`), `testNatsModeTermsAMessageThatCouldNotBeDecodedWhenTheWorkerSettlesIt` (data provider: a `MessageDecodingFailedException` message rejected when Symfony gave up, acknowledged after its retry was left out, rejected after it; all fail on 5.9.0, which NAKed it), `testSymfonyModeLeavesAMessageThatCouldNotBeDecodedToSymfonysRetryStrategy` |
| **A retry published without its delay is logged once (5.10.0)** | `testARetryPublishedWithoutItsDelayIsLoggedOnce` (`retry_via: copy`, two retries, one warning with the first delay), `testASendThatKeepsItsDelayOrHasNoneIsNotLogged` (data provider: a retry scheduled with `scheduled_messages`, a retry without a delay, a failure transport's copy, an application's own delayed message, a received message sent again with a delay and no retry count, a delayed message with a retry count that was not received here); the tests of the last delivery's copy expect the `retry_via=nak` text |
| **`setup()` in nats mode warns about a stream where NATS deletes what `max_deliver` stops (5.10.0)** | `testSetupInNatsModeWarnsAboutAStreamWhereNatsDeletesWhatMaxDeliverStops` (data provider: interest with 3 and 1 replicas on a clustered server, interest on a standalone 2.12.8 and 2.14.0, work-queue with 3 replicas on 2.12.4), `testSetupLogsNothingWhereNatsKeepsWhatMaxDeliverStops` (data provider: limits with 3 replicas, work-queue with 3 on 2.12.5 and with 1, interest on a standalone 2.12.9, 2.14.1 and `v2.12.9`, work-queue without a replica count on 2.12.4, symfony mode, nats mode without `max_deliver`), `testSetupInNatsModeWarnsWhenTheServersVersionIsUnknown` (no server info: the warning for a restart, naming an unknown version), `testSetupInNatsModeWithoutALoggerStillSetsUpAStreamThatLosesWhatMaxDeliverStops` |
| **An unrecoverable failure is TERMed in nats mode (5.10.0)** | `testNatsModeTermsAnUnrecoverableFailureAndNaksTheRest` (data provider: unrecoverable, unrecoverable wrapped by `HandlerFailedException`, an ordinary failure, a recoverable one, no error details: TERM for the first two, NAK for the rest), `testNatsModeRunsAnUnrecoverableFailureOnceThroughTheWorker` (Symfony's Worker with its error-details and retry listeners, `max_retries` 0, `max_deliver` 4: one TERM); the unrecoverable cases fail on 5.9.x, `testNatsModeNaksAFailureSymfonyRetriedWhoseFirstExceptionIsUnrecoverable` (data provider: acknowledged, rejected; two handlers, the first unrecoverable, with Symfony's retries on: NAKed either way, where the first form of 5.10.0 TERMed the rejected one) |
| **NATS-native retry tuning** | `testHandleFailedDeliveryUsesNakWithDelayWhenConfigured`, `testSetupAppliesConsumerRetryTuning` |
| **Stream setup (create)** | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupPassesNewStreamPolicyOptions`, `testSetupPassesEachTriStateStreamFlagIndependently`, `testSetupPassesNewConsumerOptions`, `testSetupCreatesNewStreamWithMaxMessages`, `testSetupCreatesNewStreamWithMaxMessagesPerSubject`, `testSetupPassesStreamPlacementOnCreate` |
| **Consumer replay policy (immutable)** | `testSetupPassesNewConsumerOptions`, `testSetupPreservesTheExistingConsumerReplayPolicyOverTheConfiguredOne`, `testSetupWritesReplayPolicyWhenExistingConsumerAlreadyMatches`, `testSetupOmitsReplayPolicyWhenTheExistingConsumerReportsNone`, `testSetupRethrowsNon404JetStreamExceptionFromConsumerLookup`, `testSetupPreservesAnOriginalReplayPolicyWhenTheOptionIsRemoved` |
| **Auto-setup provisioning** | `testAutoSetupProvisionsOnFirstSendOnce`, `testAutoSetupProvisionsOnFirstGet`, `testAutoSetupDisabledByDefaultDoesNotProvisionOnSend`, `testAutoSetupProvisionsBeforeTheFirstPublish`, `testAutoSetupProvisionsBeforeTheFirstFetch`, `testAutoSetupRetriesProvisioningAfterAFailedAttempt`, `testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEverySend`, `testAutoSetupRepeatsARejectedMoveAndScaleUpdateOnEveryGet` |
| **Auto-setup re-provisioning (missing stream or consumer)** | `testAutoSetupReprovisionsWhenTheConsumerDisappeared`, `testGetTreats404AsEmptyWithoutReprovisioningWhenAutoSetupIsDisabled`, `testAutoSetupReprovisioningRetryStopsAfterOneAttempt`, `testAutoSetupRethrowsAnUnexpectedErrorFromTheRetriedPull`, `testAutoSetupReprovisionsForEveryMissingResourceStatus`, `testGetStillPropagates503WhenAutoSetupIsDisabled`, `testCloseResetsAutoSetupSoTheNextOperationProvisionsAgain`, `testAutoSetupVerifiesTheNewConnectionBeforeTheSameCallPublishes`, `testAutoSetupDoesNotReprovisionForAnUnexpectedPullError`, `testAutoSetupReadsA408FromTheRetriedPullAsEmpty`, `testAutoSetupRetriedPullRefusedAtTheSubscriptionLimitFailsWithATransportException` (#76) |
| **A pull the server ended itself (409 Server Shutdown, Leadership Change)** | `testAPullTheServerEndedForItsShutdownIsEmptyAndTheNextOperationChecksTheConnection` (auto_setup on and off), `testAPullEndedByALeadershipChangeIsEmptyAndTheNextOneDoesNotCheckTheConnection`, `testAShutdownEndingTheRetriedPullAfterReprovisioningIsEmptyToo`, `testA409ReportingAConfigurationErrorStaysVisible` (push consumer, batch above the maximum, each with auto_setup on and off), `testOnlyA409CarriesTheServerEndedReadings` |
| **Stream setup (update existing)** | `testSetupUpdatesStreamWhenItAlreadyExists`, `testSetupUpdatesStreamWhenAlreadyInUseMessage`, `testSetupUpdatesStreamWhenAlreadyExistsInMessage`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, `testSetupUpdatesExistingStreamWithoutDuplicatingSubjects`, `testSetupUpdatesExistingStreamWithMaxMessages`, `testSetupUpdatesExistingStreamWithMaxMessagesPerSubject`, `testSetupUpdateResetsUnsetStreamLimitsToUnlimited`, `testSetupUpdateAppliesExplicitlyConfiguredStreamMaxConsumers`, `testSetupUpdateAppliesExplicitlyConfiguredStreamMaxMessageSize`, `testSetupUpdatePreservesServerRetentionOverAConfiguredOne`, `testSetupUpdateNeverCancelsAnExistingDenyFlag`, `testSetupUpdateClampsInheritedDuplicateWindowToTheConfiguredMaxAge`, `testSetupUpdateKeepsDuplicateWindowWhenMaxAgeIsUnlimited`, `testSetupUpdateConvertsConfiguredMaxAgeToNanoseconds`, `testSetupUpdateTreatsNonArrayServerSubjectsAsEmpty`, `testSetupPreservesExistingReplicaCountWhenStreamReplicasNotConfigured`, `testSetupOverridesExistingReplicaCountWhenStreamReplicasExplicitlyConfigured`, `testSetupUpdatesExistingStreamWithMaxBytes`, `testSetupUpdateLeavesOutServerSubjectsThatAreNotNonEmptyStrings` (scheduled messages off and on), `testSetupUpdateAppliesConfiguredStreamPlacementToAnExistingStream`, `testSetupUpdatePreservesServerStreamPlacementWhenNotConfigured`, `testSetupUpdateMergesConfiguredPlacementIntoTheLivePlacement` (tags only, cluster only, a cluster equal to the live one, both, neither, no live placement, a changed set, reordered and repeated tags, a case-only change, non-string live tags alone and next to their string form, a reorder with a replica change, a reorder with the live side sorted) |
| **Stream setup (error handling)** | `testSetupDoesNotTreatGenericBadRequestAsExistingStream`, `testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest`, `testSetupWrapsUnexpectedStreamCreationErrors`, `testSetupRethrowsNon404JetStreamExceptionFromStreamExistsCheck`, `testSetupWrapsConsumerCreationError`, `testSetupUpdateStreamFailureWrapsException`, `testSetupFailureKeepsTheCauseAsThePreviousException`, `testSetupExplainsHowToStageAMoveAndScaleRejection`, `testSetupKeepsThePlainWordingForOtherUpdateRejections`, `testSetupWrapsAFailureThatIsNotAJetStreamRejectionWithThePlainWording` |
| **Consumer requests of setup() while the cluster moves the consumer (err_code 10008 or 10059, or no answer, after a change of `stream_replicas`)** | `testSetupAsksForTheConsumerAgainWhileTheClusterIsTemporarilyUnavailable` (the lookup, the second answer's replay policy is used), `testSetupCreatesTheConsumerAgainWhileTheClusterIsTemporarilyUnavailable` (the create, twice), `testSetupWaitsBeforeAskingTheTemporarilyUnavailableClusterAgain` (a real pause, checked as at least 50 ms: with ext-event a 100 ms delay can end a little early), `testSetupGivesUpOnATemporarilyUnavailableClusterAfterFiveSeconds` (six requests at 0 to 5 s, then the server's message), `testSetupDoesNotAskForTheConsumerAgainAfterAnotherJetStreamError`, `testSetupAsksForTheConsumerAgainWhileTheMovedStreamIsReportedAsNotFound` (10059 on the lookup is not read as "no consumer": the live consumer's replay policy is sent, not the configured one), `testSetupCreatesTheConsumerAgainWhileTheMovedStreamIsReportedAsNotFound`, `testSetupGivesUpOnAStreamStillReportedAsNotFoundAfterFiveSeconds`, `testSetupReadsAMissingConsumerAsNoConsumerWithoutAskingAgain` (guard: 10014 is still "no consumer", at once), `testSetupAsksForTheConsumerOnceMoreAfterARequestTheClusterDidNotAnswer` (the lookup; asked again although the unanswered request took longer than the 5 s), `testSetupCreatesTheConsumerOnceMoreAfterARequestTheClusterDidNotAnswer`, `testSetupGivesUpOnASecondConsumerRequestTheClusterDidNotAnswer`, `testEachConsumerRequestGetsItsOwnSecondTryAfterAnUnansweredOne`, `testSetupGivesUpOnATemporarilyUnavailableClusterAfterAnUnansweredRequestPastFiveSeconds`; the test doubles' `pause()` fails a test after 60 real pauses (or 1000 recorded ones), so that a loop which never ends fails within seconds; the example `stream-placement.php` (from 2 replicas to 1 on the test cluster) |
| **Unsupported server feature** | `testSetupGivesClearErrorWhenScheduledMessagesUnsupported`, `testSetupWrapsUnsupportedFeatureGenericallyWhenNotScheduledMessages`, `testSetupNamesTheRejectedFeatureWhenItIsNotTheOneScheduledMessagesNeed` |
| **`scheduled_messages` refused on a server before NATS 2.12, which ignores the stream's setting instead of rejecting it** | `testSetupRefusesScheduledMessagesOnAServerWithoutSchedules` (data provider: 2.9, 2.10, 2.11 and a version with a leading `v`; the setup fails with the README's message before it creates anything), `testSendRefusesToScheduleOnAServerWithoutSchedules` (a delayed message is not published, also when `setup()` never ran in this process), `testSetupWithScheduledMessagesGoesOnWhenTheServerMaySchedule` and `testSendSchedulesOnAServerThatMaySchedule` (data provider: 2.12, a release candidate of it, 2.15, 3, a leading `v`, a version the transport cannot read, one that only holds a release further in, and no server info), `testSetupWithoutScheduledMessagesDoesNotLookAtTheServersVersion`, `testSendWithoutAScheduleDoesNotLookAtTheServersVersion` (data provider: no delay, a delay of 0, and a delay without `scheduled_messages`), `testSetupConnectsBeforeItLooksAtTheServersVersion` and `testTheFirstDelayedSendConnectsBeforeItLooksAtTheServersVersion` (the server gives its version when the connection opens, so a transport that has not connected yet connects first; both fail without that) |
| **Consumer validation** | `testSetupRejectsUnexpectedConsumerConfiguration`, `testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig`, `testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy`, `testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject`, `testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName` |
| **Message count** | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testMessageCountThatCannotConnectDialsOnceAndReturnsZero`, `testMessageCountOfALoginTheServerRefusesDialsOnceAndReturnsZero`, `testGetMessageCountReadsAStreamStateWithoutAMessageCountAsZero` |
| **Connection check (`ping_after_idle`: after idling, after a failure, and before the NAK or TERM of a failed delivery)** | `testOperationThatFailedOnTheConnectionMakesTheNextOneCheckItFirst`, `testAnsweredPingAfterAFailureKeepsTheConnectionAndEndsTheCheck`, `testJetStreamReplyDoesNotMakeTheNextOperationCheckTheConnection`, `testPullTheServerDidNotAnswerMakesTheNextOperationCheckTheConnection`, `testFailureMakesNoOperationCheckTheConnectionWhenTheCheckIsOff`, `testCloseEndsTheCheckAFailureCalledFor`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testIdleConnectionIsCheckedWithAPingAndKeptWhenTheServerAnswers`, `testIdleConnectionThatDoesNotAnswerThePingIsReplacedBeforeTheOperation`, `testFailedCloseOfTheIdleConnectionDoesNotStopItsReplacement`, `testPingUnansweredWithinTheConnectionTimeoutReplacesTheConnection`, `testRecentlyUsedConnectionIsNotPinged`, `testPingAfterIdleZeroTurnsTheCheckOff`, `testIdleTimeCountsFromTheEndOfAPull`, `testIdleTimeCountsFromTheEndOfAnEmptyPull`, `testAutoSetupVerifiesTheConnectionThatReplacedAnIdleOne`, `testAnsweredPingCountsAsUseSoOneOperationPingsOnce`, `testKeepaliveNeitherPingsNorDials`, `testConnectionUnusedForExactlyPingAfterIdleIsNotPinged`, `testPullRefusedAtTheSubscriptionLimitFailsWithATransportExceptionAndTheNextOperationChecksTheConnection` (a pull refused at the subscription limit still calls for the check, since the server may close the connection behind the error, #76), `testSettlingAFailedDeliveryChecksTheConnectionFirst` (`reject()` in symfony mode (TERM) and nats mode (NAK, with and without `nak_delay`), and the NAK `ack()` sends for a delivery whose retry copy was left out: one PING, then the acknowledgement, on a connection used a moment ago; the next ACK pings nothing), `testPingAfterIdleZeroTurnsTheCheckBeforeSettlingAFailedDeliveryOff`, `testARefusedDialDoesNotMakeTheOperationAfterTheNextDialCheckTheConnection` (a call that throws before it returns its Future, as a refused dial does, leaves the check as it was) |
| **No reference cycle from a failed call (#115)** | `testAPullThatFindsNoMessagesLeavesNoReferenceCycle` (an empty pull, ended by an exception raised in the event loop, leaves nothing for the cycle collector with `zend.exception_ignore_args` off), `testAFailedCallLeavesNoReferenceCycle` (the same for a failed send, ack, ack with `ack_sync`, TERM, NAK in nats mode, `getMessageCount()` with both lookups failing and `setup()` with the stream refused; a call site whose closure captures a Future made before the call fails its row). Both make the exception in the event loop and are skipped under Xdebug's develop mode |
| **Reconnect mode (`reconnect: true`: the client owns recovery)** | `testReconnectModeLeavesAClientInTheMiddleOfItsRecoveryAlone`, `testReconnectModeReadsAFailedPingAgainstTheClientsRecovery` (a client that is recovering keeps the connection, one that already recovered keeps it, the same connection silent is replaced, a client that gave up is dialled again), `testReconnectModeTakesARecoveredConnectionAsChecked`, `testWithoutReconnectAFailedPingReplacesTheConnectionAndTheReconnectCountIsNeverRead` |
| **Reconnect mode: provisioning after the client's own reconnect** | `testReconnectModeVerifiesProvisioningAgainAfterTheClientReconnected` (send, get), `testReconnectModeReprovisionsOnceWhenTheFirstPublishAfterTheReconnectFindsNoResponder` (plain, deduplicate on, application-supplied id, delayed message), `testReconnectModeReprovisionsOnlyOnceForAPublishWithoutAResponder`, `testReconnectModeDoesNotRetryAPublishOutsideTheNoResponderCase` (count unchanged, no auto_setup, another reply), `testWithoutReconnectAPublishWithoutAResponderPropagatesAndTheReconnectCountIsNeverRead` |
| **Connection (lazy init, dialling again after the client closed)** | `testConnectInitializesJetStreamContextFromClient`, `testJetStreamThrowsWhenConnectLeavesContextUnavailable`, `testConnectIsIdempotentAcrossOperations`, `testOperationAfterTheClientClosedDialsAgain` (send, get, ack, reject, getMessageCount), `testFailedDialAfterTheClientClosedSurfacesAsTheConnectionErrorAndIsRetried` |
| **Scheduled / delayed messages** | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`, `testSendDelayedMessageSchedulesAtRequestedDelay`, `testSendDelayedMessageNeverSchedulesBeforeRequestedDelay` (the delay is picked so that the requested time falls in the middle of a second, so the result does not depend on the clock), `testSendDelayedMessageDueOnAWholeSecondIsScheduledForThatSecond` and `testSendDelayedMessageDueWithinASecondIsScheduledForTheNextOne` (the transport is given the delivery time, `deliveryTime()`: a whole second is kept, a microsecond past one is rounded up), `testSendDelayedMessageWithLargeDelaySchedulesFarInTheFuture`, `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally`, `testSendWithZeroDelayPublishesNormally`, `testSendWithNegativeDelayPublishesNormally`, `testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders`, `testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag`, `testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject`, `testSetupUpdateRemovesOrphanedDelayedSubjectWhenScheduledMessagesDisabled`, `testSendWithoutDelayStampPublishesNormallyWhenScheduledMessagesAreEnabled` |
| **Duplicate protection (`deduplicate`, `DeduplicationIdStamp`)** | `testSendWithDeduplicationStampsTheEnvelopeAndPublishesItsMessageId`, `testSendKeepsTheDeduplicationIdTheEnvelopeCarries`, `testSendGivesEachRetryAndTheFailureTransportCopyAMessageIdOfItsOwn`, `testSendWithoutDeduplicationSendsNoMessageIdAndAddsNoStamp`, `testSendUsesAnApplicationDeduplicationIdWithTheOptionOff`, `testSendDelayedMessageWithDeduplicationPublishesItsMessageId`, `testSendGivesTheCopiesForTwoTransportsOnOneStreamMessageIdsOfTheirOwn`, `testSendGivesTheFailureCopiesOfTwoTransportsMessageIdsOfTheirOwn` |
| **Pull refused at the subscription limit (#76)** | `testPullRefusedAtTheSubscriptionLimitFailsWithATransportExceptionAndTheNextOperationChecksTheConnection` (the client's `SubscriptionLimitException` becomes a `TransportException` naming the stream, the consumer and the limit, with the full message pinned and the client's exception as previous), `testAutoSetupRetriedPullRefusedAtTheSubscriptionLimitFailsWithATransportException`, `testPullThatFailedOnTheConnectionOtherwisePropagatesAsItIs` (guard: any other client failure of a pull propagates as it is) |
| **Igbinary fallback** | `testConstructorWithoutIgbinaryDoesNotCrash` |
| **TLS DSN** | `testConstructorWithTlsDsnInitializesTransport` |

### Unanswered Pull (`tests/unit/UnansweredPullTest.php`)

Runs the real client against an in-memory server that answers the handshake and PINGs, and a pull only when told to, so a new client version that words its own pull deadline differently fails here. The server records a PING only when the client wrote it on its own: the PING client 2.19.0 writes behind each pull inbox's SUB, to learn whether the server took the subscription, is answered but not counted as a connection check.

| Feature | Tests |
|---------|-------|
| **A pull the server did not answer makes the next operation check the connection; one the server ended with 408 does not** | `testOnlyAPullTheServerDidNotAnswerMakesTheNextOneCheckTheConnection` |

### A Pull During a Graceful Stop (`tests/unit/GracefulStopPullTest.php`)

nats-server answers a pull with 503 for a consumer that does not exist and, while it is going away, for one it has
already stopped: in the last moments of a graceful stop (NATS 2.15 takes about 10 ms for each durable consumer on a file
stream) and for the whole grace period of lame duck mode (#106). After a 503 the transport asks the client why
(`pullMetAServerGoingAway()`, `JetStreamContext::diagnoseNoResponders()` of client 2.29, whose own tests cover how it
watches the connection). The first tests double the client and its JetStream context, to drive each cause and each state
of the connection; the others run the real client over the scripted server of
[Subscription Limit](#subscription-limit-testsunitsubscriptionlimitpulltestphp), whose pulls can end with status 503
(`$pullsFindNoResponder`) and which answers a CONSUMER.INFO of the consumer with `$consumerInfoReply`, with nothing, or
with an INFO that announces lame duck mode (`$lameDuckOnConsumerInfo`), behind `FaultInjectingNatsServer`, which closes
the connection right after the question went out, as the stopping server does. On the transport of 5.8.0 every test but
the three of `testOnlyA503AsksWhyThePullGotNoResponders` fails; on the transport that asked CONSUMER.INFO itself (the
first commits of #108, client 2.28), the two real-client lame duck cases fail with the 503 after request_timeout.

| Feature | Tests |
|---------|-------|
| **A 503 from a server going away is an empty batch; with auto_setup nothing is provisioned on the server going away** | `testA503FromAServerGoingAwayIsAnEmptyBatch` (auto_setup off and on), `testWithTheRealClientAPullOnAStoppingServerIsAnEmptyBatchAndTheNextOneDialsAgain` |
| **The operation after it checks the connection the client has, and leaves a recovery in flight to the client** | `testTheOperationAfterAServerGoingAwayChecksTheConnectionTheClientHas` (recovering: no check; replaced: a check of the new connection; a lame duck server's connection: a check) |
| **With `reconnect`, the client's diagnosis ends once it is recovering or has replaced the connection, not at request_timeout** | `testWithTheRealClientAndReconnectAPullOnAStoppingServerIsAnEmptyBatchWithoutWaitingForTheRequestTimeout` |
| **Lame duck mode: the pull is an empty batch while the connection stays open, and the next pull asks nothing** | `testWithTheRealClientAPullOnAServerInLameDuckModeIsAnEmptyBatchWhileTheConnectionStays` (reconnect off and on: an empty batch in under 2.5 s with a 5 s request_timeout, one session, one question for two pulls, one check of the connection) |
| **Any answer keeps the 503's reading as a missing consumer, and the next operation does not check the connection** | `testA503KeepsItsReadingWhenTheServerAnswers` (the consumer, 404 consumer or stream not found, another JetStream error), `testWithTheRealClientA503FromAServerThatAnswersTheQuestionPropagates` |
| **No answer on a connection that stays keeps the reading too, and the next operation checks the connection** | `testA503KeepsItsReadingWhenNothingAnswersOnAConnectionThatStays` (reconnect off and on), `testWithTheRealClientA503WhoseQuestionGoesUnansweredOnAConnectionThatStaysPropagates` (the question gets a 50 ms budget) |
| **Only a 503 asks why** | `testOnlyA503AsksWhyThePullGotNoResponders` (404 no messages, 409 consumer deleted, 500; guard: passes before #106 too) |
| **The pull after provisioning again gets the same reading** | `testA503OfThePullAfterProvisioningAgainFromAServerGoingAwayIsAnEmptyBatch` |

### Subscription Limit (`tests/unit/SubscriptionLimitPullTest.php`)

Runs the real client against an in-memory server (`tests/Support/SubscriptionLimitNatsServer.php`) with one stream and
one pull consumer. It answers in wire order, refuses a SUB beyond its limit with `-ERR 'maximum subscriptions exceeded'`
and keeps the connection open, stores what a send publishes, delivers it to a pull with an ack subject, and records
acknowledgements. It can also send the limit error and close the connection 20 ms later, as nats-server does when an
account's limit is lowered below what the connection holds, and signal once it has. The transport's checks are counted
through `rtt()` (`tests/Support/RttCountingNatsClient.php`), since the heartbeat and the PING behind each inbox's SUB are
PINGs too, and `ping_after_idle` stays at 30. Each test but the limit-two guard fails on 5.4.1 (#76).

| Feature | Tests |
|---------|-------|
| **At a limit of one, the pull after a request fails with a `TransportException` naming the limit; the next operation checks the connection and keeps it** | `testAtALimitOfOneThePullFailsWithATransportExceptionNamingTheLimit` (the pull's own read meets the `-ERR`), `testAtALimitOfOneAPullWhoseRefusalTheHeartbeatReadsFailsTheSameWay` (the heartbeat's read meets it while the SUB write is under way: no pull request goes out, and the listener gets the typed `-ERR`) |
| **At a limit of two, pulls deliver and acknowledge, and never need a third subscription** | `testAtALimitOfTwoPullsDeliverAndAcknowledge` (guard: passes on 5.4.1 too) |
| **The closing form: the same error, then the server closes the connection** | `testALimitErrorTheServerClosesTheConnectionBehindKeepsTheCheckBeforeTheNextOperation` (before and after the pull's inbox was confirmed; the client still reports Open, and the next send checks, finds the connection gone and goes out on a new one), `testALimitErrorTheServerClosesTheConnectionBehindLeavesAClosedClientThatIsReplacedWithoutACheck` (the heartbeat read the EOF, so the next send dials without a check) |

### Reconnect Mode (`tests/unit/ReconnectModeTest.php`)

Runs the real client with reconnect on over the scripted server behind a connection that fails the way a real one does
(`tests/Support/FaultInjectingNatsServer.php`): it can go silent, fail one write, close from the server's side (with
the client reading or not, and, not read, seen or not by the client's `peerClosed()` check of client 2.28.0), go down
right after it took a write, hold the next dial or refuse every dial. A PHP process runs the event loop only inside a
transport call, so the client notices a
lost connection when an operation writes to it; that operation starts the recovery, and whether it waits for it or
times out and leaves the client Connecting depends on whether the dial ends within its budget. An operation that has to
succeed gets 5 s, which it never uses up, only the send a test runs out of its budget gets 50 ms, and a recovery a test
looks at while it is in flight is held until the test releases it, so nothing here depends on how fast the process runs
(see [Unit Tests With Pauses](#unit-tests-with-pauses-testsunitruntestswithpausestestphp)). The client's reconnect delay is
1 ms. The transport's PINGs are counted through `rtt()`; the client's heartbeat is off (#81).

| Feature | Tests |
|---------|-------|
| **An operation that runs into the lost connection starts the client's recovery; the next one finds the client Connecting, sends no PING, dials nothing and waits for the recovery** | `testAnOperationThatRunsIntoTheLostConnectionStartsTheRecoveryAndTheNextOneWaitsForIt` |
| **The operation itself succeeds when the client recovers within its budget** | `testAnOperationThatRunsIntoTheLostConnectionSucceedsWhenTheClientRecoversWithinItsBudget` |
| **A silent connection the client does not know to be lost is replaced, as without the option** | `testASilentConnectionTheClientDoesNotKnowToBeLostIsReplaced` |
| **A connection the client recovered behind the transport's failed PING is kept (two connections, not three)** | `testAConnectionTheClientRecoveredBehindAFailedPingIsKept` |
| **A recovery started by the failed PING is left to run; the operation waits for it** | `testARecoveryStartedByAFailedPingIsLeftToRunAndTheOperationWaitsForIt` |
| **A pull that runs into the lost connection ends empty and the next one delivers** | `testAPullThatRunsIntoTheLostConnectionEndsEmptyAndTheNextOneDelivers` |
| **An ACK or TERM whose own write is the first to notice the lost connection returns at once, and the recovery sends it on the new connection** | `testAnAcknowledgementWhoseWriteFindsTheConnectionLostReturnsAtOnceAndTheRecoverySendsIt` (`ack()` and `reject()`; both fail on client 2.27.0, where the call waited for the held dial, which is why the transport requires 2.27.1, client #244; `reject()` checks the connection first, and the PING is still answered there) |
| **The NAK of a failed delivery reaches NATS after the server closed the connection during the handler** | `testTheNakOfAFailedDeliveryReachesTheServerThatReplacedOneWhichClosedTheConnectionDuringTheHandler` (`reject()`, and `send()` of the retry copy plus `ack()`, each on a plain socket and with the close behind unread bytes: the server closed the connection without the client reading it, `FaultInjectingNatsServer::closeUnread()`; a write there succeeds and is lost, as one after a peer's FIN is. The PING's wait reads the close, the client recovers, and the NAK reaches the server on the new connection; the two cases behind unread bytes, which the client's own check cannot see, fail without the PING) |
| **The ACK of a handled message after such a close: no PING first, and since client 2.28.0 the client sees the close before the write and sends the ACK on the recovered connection** | `testTheAckOfAHandledMessageReachesTheServerOnTheRecoveredConnectionWithNoCheckFirst`, `testTheAckOfAHandledMessageIsStillLostToACloseBehindUnreadBytes` (guard and limit: a close behind unread bytes, as over TLS, still takes the ACK) |
| **Symfony's retry copy after such a close goes out on the recovered connection (client 2.28.0)** | `testSymfonysRetryCopyAfterTheServerClosedTheConnectionDuringTheHandlerGoesOutOnTheRecoveredConnection` |
| **A send whose server went down after it took the frame is written again on the recovered connection only with `resend_on_reconnect` and a Nats-Msg-Id (client #249)** | `testASendWhoseServerWentDownBeforeItAnsweredIsWrittenAgainOnlyWithResendOnReconnectAndAMessageId` (with the option and `deduplicate`: stored on the new connection; with the option and no id, or with an id and no option: `TimeoutException`, nothing written again) |
| **A recovery that gives up with a buffered acknowledgement is reported to the logger at error level, and the next operation dials again and fails with the client's exception** | `testAReconnectThatGivesUpWithABufferedAcknowledgementIsReportedToTheLoggerAndTheNextOperationFails` |

### Bundle (`tests/unit/IDCTNatsMessengerBundleTest.php`)

Each test boots a kernel with FrameworkBundle and Messenger (`tests/Support/MessengerTestKernel.php`) and takes the
transports from its container, so it sees the tags autoconfiguration adds and the decorators Messenger puts around a
transport's serializer. Building a NATS transport opens no connection, so no server is needed.

| Feature | Tests |
|---------|-------|
| **Transport factory registration** | `transportFactoryIsRegisteredAndTaggedForMessengerDiscovery` (a NATS DSN gets a `NatsTransport`), `withoutTheBundleNoTransportFactorySupportsANatsDsn` (nothing registers the factory from vendor/ on its own) |
| **The factory under its class name: autowirable, and an application's own entry replaces it** | `anApplicationServiceCanAutowireTheTransportFactory`, `anApplicationsOwnFactoryEntryReplacesTheBundlesFactory` (one NATS factory, not two) |
| **The services.yaml entries used before the bundle keep working, next to it and without it** | `theServiceEntriesUsedBeforeTheBundleKeepWorking` (data provider; the factory with its tag, the serializer under its class name and under `igbinary_serializer`) |
| **Flex can still find the bundle class** | `flexFindsTheBundleTheWayItGuessesBundleClasses` (mirrors symfony/flex's guess from the PSR-4 prefix and its look for the bundle base class in the file) |
| **IgbinarySerializer registration** | `theReadmesIgbinaryExampleGivesTheTransportTheIgbinarySerializer` (the README's example, with the bundle and nothing else) |
| **The README's setup without the bundle** | `theReadmesSetupWithoutTheBundleGivesAWorkingTransport` (its two "only without the bundle" services.yaml snippets and its igbinary example, in an application without the bundle) |
| **One id for the serializer: Messenger wraps it for signing once, so a signed message decodes** | `aSignedMessageSurvivesTheSerializerUnderEveryIdTheBundleGivesIt` (one transport per id the bundle gives the serializer; Symfony 7.4+, needs igbinary) |
| **The application's `logger` service reaches the NATS client of every transport, and the client gets none without it** | `theBundleHandsTheApplicationsLoggerToTheFactory`, `theFactoryIsTaggedForMonologsMessengerChannel` |
| **The README's `nkey_seed` forms: each gives a signer for a seed, `default::` means no seed for an unset or empty variable, and the required forms are refused when empty** | `theReadmesOptionalSeedFormMeansNoSeedForAnUnsetOrEmptyVariable`, `theReadmesOptionalSeedFormGivesASignerWhenTheVariableHasASeed`, `theReadmesRequiredSeedFormGivesASignerWhenTheVariableHasASeed`, `theReadmesSeedFileFormGivesASignerWhenTheFileHasASeed`, `theReadmesRequiredSeedFormIsRefusedForAnEmptyVariable`, `theReadmesSeedFileFormIsRefusedForAnEmptyFile` |
| **The README's `jwt` form** | `theReadmesJwtFormGivesTheClientTheJwtAndASigner` |

The functional Behat suite also exercises the bundle end-to-end: the test app enables it in
`config/bundles.php` and every scenario relies on its service registration.

### Transport Factory (`tests/unit/NatsTransportFactoryTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN scheme support** | `supports_WithNatsJetStreamScheme_ReturnsTrue`, `supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue`, `supports_WithNatsJetStreamTlsScheme_ReturnsTrue`, `supports_WithDifferentScheme_ReturnsFalse`, `supports_WithNatsButNotJetStream_ReturnsFalse`, `supports_WithAmqpScheme_ReturnsFalse`, `supports_WithEmptyString_ReturnsFalse`, `supports_WithHttpScheme_ReturnsFalse` |
| **Transport creation** | `createTransport_WithValidDsn_ReturnsNatsTransportInstance`, `createTransport_WithOptions_PassesOptionsToTransport`, `createTransport_UsesProvidedSerializer`, `createTransport_WithDefaultPort_ParsesDsnCorrectly`, `createTransport_WithoutAuth_ParsesDsnCorrectly`, `createTransport_WithQueryParams_ParsesConfigCorrectly`, `createTransport_HandsTheFactorysLoggerToTheClient` |

### Configuration Builder (`tests/unit/Options/NatsTransportConfigurationBuilderTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing** | `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `testBuildWithExtraPathSegmentsThrowsException`, `testBuildWithMalformedDsnThrowsException`, `testBuildWithDsnMissingHostThrowsException`, `testBuildWithDottedTopicNameSucceeds`, `testBuildUsesThePortTheDsnGives` |
| **Option merging (query + options)** | `testBuildOptionsOverrideQueryOptions`, `testBuildMethodOptionsOverrideQueryForStreamStorageAndPerSubjectLimit`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues`, `testBuildAcceptsStreamStorageInAnyCase` |
| **An unknown option is refused (5.10.0)** | `testBuildRefusesAnUnknownOption` (data provider: in the options and in the DSN query, a letter more, two letters swapped, in capitals, without the word before or after it, the nearest of several, nothing near, a list entry, after a known option, with no value, two letters swapped in a short name, a quarter of a long name changed and more than that, the start or the end of a name not at an underscore, equally near two options (the first, in the enum's order), after a parameter with no name, a number as the name; each with the closest option when one is near), `testBuildAcceptsWhatNamesNoUnknownOption` (`transport_name` in the options and the query, empty parameters, a parameter with no name, a list, a known option after a credential) |
| **Validation** | `testBuildWithEmptyConsumerThrowsException`, `testBuildWithInvalidBatchingThrowsException`, `testBuildWithNonNumericBatchingThrowsException`, `testBuildWithNegativeBatchingThrowsException`, `testBuildWithNonIntegerBatchingFloatThrowsException`, `testBuildWithArrayBatchingThrowsException`, `testBuildWithInvalidConnectionTimeoutThrowsException`, `testBuildWithNegativeConnectionTimeoutThrowsException`, `testBuildWithNonNumericConnectionTimeoutThrowsException`, `testBuildWithZeroMaxBatchTimeoutThrowsException`, `testBuildWithNegativeMaxBatchTimeoutThrowsException`, `testBuildWithNonNumericMaxBatchTimeoutThrowsException`, `testBuildWithInvalidStreamReplicaCountThrowsException`, `testBuildWithNegativeStreamReplicasThrowsException`, `testBuildWithNonIntegerStreamReplicasThrowsException`, `testBuildWithNonNumericStreamMaxAgeThrowsException`, `testBuildWithNonIntegerStreamMaxAgeThrowsException`, `testBuildDecodesDsnCredentialsWithRawUrlDecodePreservingLiteralPlus`, `testBuildWithInvalidStreamStorageThrowsException`, `testBuildWithWildcardInStreamNameThrowsException`, `testBuildWithSpaceInTopicThrowsException`, `testBuildWithDotInStreamNameThrowsException`, `testBuildWithGreaterThanInTopicThrowsException` |
| **Stream max messages validation** | `testBuildWithNegativeStreamMaxMessagesThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesThrowsException`, `testBuildWithStreamMaxMessagesFromQueryString` |
| **Stream max messages per subject validation** | `testBuildWithNegativeStreamMaxMessagesPerSubjectThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesPerSubjectThrowsException` |
| **Stream max bytes validation** | `testBuildWithNegativeStreamMaxBytesThrowsException` |
| **Connection timeout propagation** | `testBuildWithConnectionTimeoutPropagatesMs` |
| **Request timeout (`request_timeout`)** | `testRequestTimeoutDefaultsToTenSeconds`, `testBuildWithRequestTimeoutPropagatesMs` (options, DSN query, options over query), `testBuildWithInvalidRequestTimeoutThrowsException` (zero, negative, non-numeric), `testReadmeConfigurationOptionsAreAccepted` |
| **Timeout floors and defaults** | `testBuildClampsSubMillisecondTimeoutsToOneMs` (connection and request timeouts below half a millisecond become 1 ms), `testBuildUsesTheDefaultTimeoutsForNullOptions` (null keeps 1 s and 10 s) |
| **Client reconnect off by default, pedantic mode off** | `testBuildTurnsClientReconnectAndPedanticModeOff` (the accessors and the client's options, with the client's own attempt default kept) |
| **Idle connection check (`ping_after_idle`)** | `testPingAfterIdleDefaultsToThirtySeconds`, `testPingAfterIdleAcceptsZeroAndFractionsFromTheQueryAndTheOptions`, `testBuildWithInvalidPingAfterIdleThrowsException` (negative, non-numeric), `testReadmeConfigurationOptionsAreAccepted` |
| **Retry handler** | `testBuildUsesRetryHandlerFromQuery`, `testBuildWithInvalidRetryHandlerThrowsException` |
| **`retry_via` (#113)** | `testRetryViaIsCopyUnlessSetToNak` (the default, the options, the DSN query, the options over the query, and null in nats mode), `testBuildWithInvalidRetryViaThrowsException`, `testBuildRefusesRetryViaInNatsMode` (data provider: `copy`, `nak`, and `nak` in the DSN), `testBuildRefusesAPositiveNakDelayWithNakRetries` (0, the default, is accepted), `testBuildRefusesBackoffWithNakRetries`, `testRetryViaCopyStillAcceptsNakDelayAndBackoff` (without `retry_via` and with `copy` both stay accepted, as before) |
| **TLS configuration** | `testBuildWithTlsSchemeUsesTlsServerProtocol`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, `testBuildRecognizesTheTlsSchemeInAnyCase`, `testReadmeClusterOptionsAreAccepted` (the `+tls` scheme with `tls_required`, as a cluster needs) |
| **TLS peer verification stays on unless explicitly disabled** | `testTlsVerifyPeerStaysOnUnlessExplicitlyDisabled` (data provider: false/0/no/off disable it; recognized truthy, unrecognized, empty, null and non-scalar values keep it on), `testTlsVerifyPeerInTheDsnStaysOnUnlessExplicitlyDisabled` (DSN query: left out, empty, unrecognized, false, off) |
| **Authentication** | `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent`, `testBuildNormalizesStringBooleanAndNullableStringOptions`, `testBuildNormalizesIntegerBooleanOptions`, `testBuildPassesScalarCredentialOptionsAsStrings` (int, float, true), `testBuildFallsBackToTheDsnCredentialsForNonScalarOptions` |
| **NKey authentication (`nkey_seed`)** | `testBuildWithNkeySeedPassesASignerAndTheSeedsPublicKey` (a signer and the derived public key reach the client, no user or password), `testBuildReadsTheNkeySeedFromTheDsnQuery`, `testAnExplicitNullNkeySeedOptionHidesASeedInTheDsnQuery`, `testBuildWithANullNkeySeedHasNoSigner`; refused when the transport is built: `testBuildRejectsAnInvalidNkeySeedWithoutRepeatingIt` (the message never contains the seed, and no previous exception holds it), `testBuildSaysWhatASeedLooksLikeForAValueThatIsNotOne` (the public key, a JWT, a whole `.creds` file), `testBuildRejectsASeedThatIsNotAUserSeed` (account, operator, server, cluster, curve), `testBuildRejectsAnNkeySeedThatIsNotANonEmptyString` (empty, blank, boolean, number, list, object), `testBuildRejectsANkeySeedInTheDsnQueryThatIsNotANonEmptyString`, `testBuildRejectsASeedNextToAUsernamePasswordOrToken` (from the DSN or the options; the error repeats no value), `testWithoutTheSodiumExtensionASeedIsRefusedWhenTheTransportIsBuilt` (a child process with the client's sodium function disabled), `testADisabledSodiumFunctionIsRefusedWhenTheTransportIsBuiltWithoutChainingTheError` (another sodium function disabled, whose error holds the seed in its frames), `testAFailureInsideSodiumIsRefusedWithoutSayingWhatASeedLooksLike` (the polyfill's failure, by a stand-in in the client's namespace); without a seed no signer is passed: `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`. The README's `%env()%` forms are under Bundle, and what a failed build leaves in its exception under Secrets in Exceptions |
| **The `nkey` option** | `testBuildAcceptsAnNkeyThatIsTheSeedsPublicKey` (the signer stays), `testBuildRejectsAnNkeyThatIsNotTheSeedsPublicKey` (the error names the seed's key and never repeats the value, which may be a seed), `testBuildRejectsAnNkeyWithoutASeed` (the same), `testTheNkeyOptionIsNotDeprecated` (the seed alone, and `nkey` repeating its key) |
| **The `jwt` option** | `testBuildPassesAJwtWithItsSeedToTheClient` (the JWT, the seed's public key and the signer reach the client; the login itself is in the functional suite), `testBuildRejectsAJwtWithoutASeed`, `testBuildRejectsAJwtOptionThatIsNotAJwtWithoutRepeatingIt` (a seed, a whole `.creds` file, the wrong number of parts), `testBuildAcceptsAJwtWithSpaceAroundItAndBase64UrlCharacters` |
| **Option coercion edge cases** | `testBuildWithNonScalarOptionsCoerceToSafeDefaults`, `testBuildCoercesNonZeroIntegerBooleanOptionToTrue`, `testBuildCoercesUppercaseBooleanStringToTrue`, `testBuildWithPathMissingTopicThrowsException` |
| **Scheduled messages** | `testBuildWithScheduledMessagesEnabledSetsFlag`, `testBuildWithScheduledMessagesDisabledByDefault`, `testBuildWithScheduledMessagesFromDsnQueryString` |
| **Acknowledgement (ack_sync)** | `testBuildWithAckSyncEnabledSetsFlag`, `testBuildWithAckSyncDisabledByDefault`, `testBuildWithAckSyncFromDsnQueryString` |
| **Deduplication (`deduplicate`)** | `testDeduplicateIsOffByDefaultAndCanBeEnabled` (default, options, DSN query, options over query), `testReadmeConfigurationOptionsAreAccepted` |
| **Extended stream/consumer options + auto_setup** | `testBuildAcceptsAndNormalizesNewStreamAndConsumerOptions`, `testBuildLeavesNewOptionsUnsetByDefault`, `testBuildParsesNewOptionsFromDsnQuery`, `testBuildWithInvalidRetentionThrowsException`, `testBuildWithInvalidDiscardThrowsException`, `testBuildWithInvalidCompressionThrowsException`, `testBuildWithInvalidReplayPolicyThrowsException`, `testBuildWithDuplicateWindowExceedingMaxAgeThrowsException`, `testBuildAllowsDuplicateWindowWhenMaxAgeIsUnlimited`, `testBuildAllowsDuplicateWindowEqualToMaxAge`, `testBuildWithInvalidMaxAckPendingThrowsException`, `testBuildWithInvalidStreamMaxConsumersThrowsException`, `testBuildWithZeroStreamMaxMessageSizeThrowsException`, `testBuildWithStreamMaxMessageSizeExceedingInt32ThrowsException`, `testBuildAcceptsStreamMaxMessageSizeAtTheInt32Boundary`, `testBuildWithZeroDuplicateWindowThrowsException`, `testBuildWithOverlongStreamDescriptionThrowsException`, `testBuildAcceptsStreamDescriptionAtTheLengthLimit`, `testBuildWithUnrecognizedTriStateBooleanThrowsException`, `testBuildAcceptsEveryRecognizedBooleanTokenForTriStateFlags`, `testBuildTreatsANullMaxAgeAsUnlimitedForTheDuplicateWindow`, `testBuildWithInvalidInactiveThresholdThrowsException` (zero, negative, non-numeric), `testBuildRejectsAnUnrecognizedValueInAnyTriStateFlag` (each later flag, after a boolean `stream_deny_delete`) |
| **NATS-native retry tuning** | `testBuildRetryTuningDefaults`, `testBuildAcceptsNatsRetryTuningOptions`, `testBuildWithNegativeNakDelayThrowsException`, `testBuildWithNonPositiveAckWaitThrowsException`, `testBuildWithNonIntegerMaxDeliverThrowsException`, `testBuildWithNonListBackoffThrowsException`, `testBuildWithNonNumericBackoffElementThrowsException`, `testBuildWithMaxDeliverNotExceedingBackoffThrowsException`, `testBuildWithBackoffFromDsnQueryString`, `testBuildAcceptsZeroAndFractionalBackoffEntries`, `testBuildAcceptsMaxDeliverWithoutBackoff` |
| **Stream placement** | `testBuildAcceptsStreamPlacementOptions`, `testBuildSplitsCommaSeparatedStreamPlacementTags`, `testBuildParsesStreamPlacementFromDsnQueryString`, `testBuildLeavesStreamPlacementUnsetByDefault`, `testBuildWithInvalidStreamPlacementClusterThrowsException`, `testBuildWithInvalidStreamPlacementTagsThrowsException` |
| **Reconnect** | `testBuildTurnsClientReconnectAndPedanticModeOff`, `testBuildWithReconnectOptionsPropagatesToNatsOptions`, `testBuildWithReconnectFromDsnQueryString`, `testBuildKeepsTheClientReconnectAttemptDefaultWhenOnlyReconnectIsEnabled`, `testBuildWithInvalidMaxReconnectAttemptsThrowsException`, `testBuildRejectsAMaxReconnectAttemptsThatDoesNotSurviveTheCastToAnInt`, `testBuildRejectsAMaxReconnectAttemptsInTheDsnThatDoesNotSurviveTheCastToAnInt`, `testBuildPassesALargeMaxReconnectAttemptsToTheClient`, `testBuildPassesALargeMaxReconnectAttemptsFromTheDsnToTheClient`, `testBuildPassesTheLoggerToTheClientAndNoneByDefault` |
| **Sending again on the recovered connection (`resend_on_reconnect`)** | `testResendOnReconnectIsOffByDefault`, `testBuildWithResendOnReconnectPropagatesToNatsOptions` (options, DSN, options over DSN), `testBuildWithResendOnReconnectWithoutReconnectThrowsException`, `testBuildWithResendOnReconnectAndADuplicateWindowNotLongerThanTheRequestTimeoutThrowsException` (equal, and shorter), `testBuildWithResendOnReconnectAcceptsALongerOrAnUnsetDuplicateWindow` |
| **Option completeness** | `testDefaultOptionsCoversAllTransportOptionCases` |

### Secrets in Exceptions (`tests/unit/Options/SecretsInExceptionsTest.php`)

What a failed build leaves in its exception with exception arguments on (`zend.exception_ignore_args=0`), read from its
message, its string form, and the frames of this package and the client, along the chain of previous exceptions. Each
test keeps the secret out of its own frames, which PHP records too.

| Feature | Tests |
|---------|-------|
| **Every place in the build that throws** | `testNoFrameOfAFailedBuildHoldsASecret` (a seed or a password, in the options or the DSN, at each place that throws, an unknown option included), `testNoFrameOfAFailedTransportCreationHoldsASecret` (the factory and the constructor above the build) |
| **A new method cannot bring them back** | `testEveryParameterThatCanHoldASecretIsMarkedSensitive` (reflection over the builder, the factory and the constructor) |
| **A mistyped seed** | `testAMistypedSeedStaysOutOfTheFailure` (the client's exception, whose frames hold the value, is not chained) |
| **An `nkey` that contradicts the seed** | `testAContradictingNkeyLeavesTheSeedAndTheSignerOutOfTheFailure` (neither the seed nor the signer's secret key) |
| **A query typed with `&`, `;` or a second `?`** | `testASeedTypedIntoTheDsnPathStaysOutOfTheError` (the stream or topic name error), `testASeedTypedIntoAnOptionsValueStaysOutOfTheError` (the errors that print an option's value), `testASeedInTheValueOfANumericOptionStaysOutOfTheFrames` (the numeric check's frame) |
| **A credential after a second `?` or a `;` in the query is refused** | `testACredentialTypedAfterASecondQuestionMarkOrASemicolonIsRefusedWithoutRepeatingIt` (all seven credential options, encoded and not and in capitals, in a file name, a description, a list and a number, also with a space, a tab or a line end next to the name), `testAValueThatOnlyLooksLikeACredentialInAQueryIsAccepted` (in the options, after a proper `&`, a name that only starts or ends like one, an encoded `&` or a double encoding, which stay in the value) |
| **An unknown name after a credential, or one that is not plain, is not repeated (5.10.0)** | `testTheRestOfACredentialCutByAnAmpersandIsRefusedWithoutRepeatingIt` (all seven credential options, with an `=` in the rest and with two `&`: the rest of the seed is in neither the message nor the frames), `testAnUnknownNameThatIsNotPlainIsNotRepeated` (an encoded `?` and `=`, more than 50 characters) |
| **A plain mistake is still repeated** | `testAPlainMistakeIsRepeatedInTheError` (names and values of at most 50 plain characters), `testAValueLongerThanTheLimitIsNotRepeated`, `testAValueEndingInANewlineIsNotRepeated`, `testAShortValueWithACharacterOfAQueryIsNotRepeated`, `testAShortNameWithACharacterOfAQueryIsNotRepeated` (a short credential typed into a value or a name), `testAListWhereABooleanBelongsIsNotShownAsAValue` |
| **After a build that worked** | `testTheConfigurationDoesNotKeepTheTextOfTheSeed` (a dump of the configuration does not print it; the client's signer still holds the raw key) |

### Login Combinations (`tests/unit/LoginCombinationsTest.php`)

The boundary between the transport and the client, on every `composer test`: the options the builder really builds, taken by
the real client to the scripted server, with no Docker. The other unit tests stop at the values reaching `NatsOptions`, which
is how `jwt` and `nkey` shipped unable to log in. This cannot show that a server accepts a login: the functional suite and
the examples do. With the client's `encodeConnect` made to ignore credentials or to throw on JWT and NKey, these tests fail.

| Feature | Tests |
|---------|-------|
| **All 32 combinations of a user in the DSN, a token, `jwt`, `nkey` and `nkey_seed`** | `testCredentialsAreRefusedWhenBuiltOrReachTheServerInTheConnect` (refused when built, by the rule the test states, or a CONNECT carrying exactly those credentials, with a signature that verifies against the seed's public key) |
| **A username and password as options** | `testAUsernameAndPasswordAsOptionsReachTheServerInTheConnect` |
| **A seed on a server that sends no nonce** | `testASeedOnAServerThatSendsNoNonceCannotLogIn` |

### Test NKeys (`tests/unit/TestNkeysTest.php`)

`tests/Support/TestNkeys.php` builds the NKeys of the unit tests when they run, so that no private key sits in the repository
(#9). A mistake in it would make a transport test pass or fail for the wrong reason.

| Feature | Tests |
|---------|-------|
| **They are real NKeys** | `testTheClientReadsTheKeysAndDerivesTheSamePublicKey` (all six key types) |
| **A name gives one key** | `testANameAlwaysGivesTheSameKeyAndAnotherNameAnother` |

### Configuration (`tests/unit/Options/NatsTransportConfigurationTest.php`)

| Feature | Tests |
|---------|-------|
| **Type coercion** | `testTypedAccessorsNormalizeScalarValues` (including negative `nak_delay` and `backoff` values, which read as 0), `testTypedAccessorsProvideDefaults` (including the 1 s connection timeout and the 0 ms NAK delay), `testTypedAccessorsTruncateFloatValues` |
| **Scheduled messages accessor** | `testScheduledMessagesAccessorReturnsConstructorValue`, `testScheduledMessagesDefaultsToFalse` |
| **Ack sync accessor** | `testAckSyncAccessorReturnsConstructorValueAndDefaultsToFalse` |
| **Extended stream/consumer accessors + auto_setup** | `testNewStreamAndConsumerAccessorsReturnConfiguredValues`, `testNewStreamAndConsumerAccessorsDefaultToNull`, `testInactiveThresholdIsClampedToAtLeastOneMillisecond`, `testAutoSetupAccessorReturnsConstructorValue` |
| **Connection timeout and idle check accessors** | `testConnectionTimeoutAndPingAfterIdleAccessors` |
| **Deduplication accessor** | `testDeduplicationAccessorReturnsConstructorValueAndDefaultsToFalse` |
| **Stream placement accessors** | `testStreamPlacementAccessorsBuildThePlacementBlock`, `testStreamPlacementAccessorsTreatBlankAndMissingValuesAsUnset` (blank, missing, and a cluster of a type the builder rejects) |
| **Reconnect accessors** | `testReconnectAccessorsReadTheOptions` |

### Serializers (`tests/unit/Serializer/`)

| Feature | Tests |
|---------|-------|
| **Abstract serializer encode/decode** | `encode_WithValidEnvelope_ReturnsArrayWithBody`, `encode_WithEnvelopeContainingStamps_PreservesStampsInBody`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `decode_WithEmptyBody_ThrowsMessageDecodingFailedException`, `decode_WithMissingBody_ThrowsMessageDecodingFailedException`, `decode_WithNullBody_ThrowsMessageDecodingFailedException`, `decode_WhenDeserializeReturnsNonEnvelope_ThrowsMessageDecodingFailed`, `encode_ThenDecode_ReturnsEquivalentEnvelope`, `encode_WithMultipleStamps_PreservesAllStamps`, `encode_WithValidEnvelope_IncludesHeadersKey`, `encode_WithNonSendableStamps_LeavesThemOutAndKeepsTheRest`, `encode_GivesHeadersTheEnvelopeWithoutNonSendableStamps` |
| **Igbinary serializer** | `serialize_WithValidEnvelope_ReturnsSerializedString`, `serialize_WithEnvelopeContainingStamps_PreservesStamps`, `deserialize_WithValidSerializedData_ReturnsOriginalData`, `deserialize_WithSerializedEnvelope_ReturnsEnvelope`, `deserialize_WithInvalidData_ReturnsNull`, `deserialize_WithEmptyString_ReturnsFalse`, `encode_WithValidEnvelope_ReturnsArrayWithBody`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `decode_WithEmptyBody_ThrowsMessageDecodingFailedException`, `decode_WithMissingBody_ThrowsMessageDecodingFailedException`, `decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed`, `encode_EnvelopeAWorkerIsHandling_EncodesWithoutItsNonSendableStamps` |
| **Every failure of a decode is a MessageDecodingFailedException (5.10.0)** | `decode_WhoseDeserializeThrows_ThrowsMessageDecodingFailedWithTheCause` (the exception is the previous one), `decode_WhoseDeserializeThrowsADecodingFailure_ThrowsItAsItIs`, `decode_WhoseDeserializeRaisesANotice_ThrowsMessageDecodingFailed`, `decode_WhoseDeserializeRaisesADeprecation_PassesItOnAndDecodes` (to the error handler there was, which is in place again afterwards), `decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed` (igbinary's warning is the failure and is not raised), `decode_WithInvalidSerializedDataUnderAnErrorHandlerThatThrows_ThrowsMessageDecodingFailed` (as Symfony's error handler throws a warning in debug mode) |

### Type Coercion (`tests/unit/TypeCoercionTest.php`)

| Feature | Tests |
|---------|-------|
| **`intValue()` coercion** | `testIntValue` (data provider: int/float-truncation/numeric-string/scientific/non-numeric/null/bool/array/object/defaults), `testIntValueDefaultIsZeroWhenOmitted` |
| **`floatValue()` coercion** | `testFloatValue` (data provider: float/int-widening/numeric-string/scientific/non-numeric/null/bool/array/object), `testFloatValueDefaultIsZeroWhenOmitted` |
| **`stringValue()` coercion** | `testStringValue` (data provider: string/empty/int/float/bool-true/bool-false/null/array/object), `testStringValueDefaultIsEmptyStringWhenOmitted` |
| **`boolValue()` coercion** | `testBoolValue` (data provider: bool/int/truthy-tokens/falsy-tokens/case-insensitivity/unrecognized-string/empty/null/array/object, with both default values), `testBoolValueDefaultIsFalseWhenOmitted` |
| **`secondsToMs()` conversion** | `testSecondsToMs` (data provider: whole/fractional/numeric-string/sub-ms-rounding up and down/zero/non-numeric/null/array), `testSecondsToMsDefaultIsZeroWhenOmitted` |
| **`stringListValue()` coercion** | `testStringListValue` (data provider: list/trimming/blank-drop/de-duplication/int-elements/non-scalar-drop/associative/comma-separated/single/int/empty/null/bool/float/object) |
| **`nonBlankStringValue()` coercion** | `testNonBlankStringValue` (data provider: string/trimming/int/inner-whitespace/empty/blank/null/bool/float/array/object) |
| **Static & pure** | `testMethodsAreStaticAndPure` |

### Stamps (`tests/unit/Stamp/`)

| Feature | Tests |
|---------|-------|
| **Deduplication id** | `testKeepsTheId`, `testRejectsAnIdAHeaderCannotCarry` (data provider: empty, blank, carriage return, line feed) |

### README Examples (`tests/unit/ReadmeExamplesTest.php`)

| Feature | Tests |
|---------|-------|
| **Every README ` ```php ` block is valid PHP** | `testReadmePhpExampleIsSyntacticallyValid` (data provider, one case per block), `testReadmeContainsThePhpExamplesWeExpect` |
| **The README names no NKey to paste** | `testTheReadmeNamesNoUsableNkey` (no public key or seed: a server example gets pasted, so a reader must generate their own pair) |

### Composer Scripts (`tests/unit/ComposerScriptsTest.php`)

| Feature | Tests |
|---------|-------|
| **`composer test:functional` runs Behat, and runs past Composer's limit of 600 seconds** | `testTheFunctionalSuiteHasNoProcessTimeout` |

### Unit Tests With Pauses (`tests/unit/RunTestsWithPausesTest.php`)

`composer test:unit:paused` (`scripts/run-tests-with-pauses.php`, in CI on the PHP 8.5 job) runs the unit tests and stops
their process at random moments, for 250 ms each time and with 20 to 120 ms between two stops, as a machine short of CPU
does. It loads nothing, as a stopped process uses no CPU. `TEST_PAUSE_MS` sets the length of a stop.

A test that needs this process to keep up fails there nearly every time, where it fails only now and then on a busy
machine, so a unit test must not depend on it. An operation that has to succeed gets a budget far longer than a pause. Only
an operation that has to run out of its budget gets a short one, as a pause then just ends it sooner. A recovery a test looks
at while it is in flight waits for the test instead of for a time. A bound on how long something took stays far from what it
normally takes: a lower bound comes from a timer the operation has to wait for, and an upper bound from the time the
wrong behaviour would take.

Before these rules, of 8 runs of the whole suite at each length, 2 failed at 60 ms, 8 at 120 ms and 8 at 250 ms. After them
none did, and none of 4 runs at 500 ms or of 3 at 1 s.

| Feature | Tests |
|---------|-------|
| **The tests are really paused, and arguments reach PHPUnit** | `testTheTestsArePausedAndTheirArgumentsReachPhpunit` |
| **A failed run fails the CI step** | `testThePhpunitExitCodeIsTheScripts` |

### Mutation Testing Harness (`tests/unit/MutationTestingBootstrapTest.php`, `tests/unit/MutationTestingChecksTest.php`, `tests/unit/MutationTestingCommandsTest.php`)

`MutationTestingBootstrapTest` starts PHP the way a mutant process starts: `vendor/autoload.php`, which PHPUnit
requires first, then Infection's include interceptor on `file://`, then `tests/bootstrap.php`.
`MutationTestingChecksTest` runs the two scripts CI runs around the mutation run, and
`MutationTestingCommandsTest` checks the Infection commands in `composer.json`. The first two start child
processes through `tests/Support/PhpProcess.php`, and the bootstrap tests are skipped when
`infection/include-interceptor` is not installed.

| Feature | Tests |
|---------|-------|
| **Mutant processes can double final classes and load the mutated file** | `testMutantProcessCanDoubleFinalClassesAndLoadsTheMutatedFile`, `testMutantProcessOfInfectionsPharCanDoubleFinalClassesAndLoadsTheMutatedFile` (the namespace-prefixed interceptor of Infection's PHAR) |
| **A source file loaded before Infection's bootstrap is not loaded again** | `testSourceFileLoadedBeforeInfectionsBootstrapIsNotLoadedAgain` (a Composer "files" entry is loaded unmutated before the interceptor is on; loading the mutated copy on top would end the process with a redeclaration error, which Infection counts as a kill, so the mutant escapes instead) |
| **Canary: every mutant that changes nothing escapes** | `testCanaryPassesWhenEveryMutantEscaped`, `testCanaryFailsWhenAMutantWasDetectedOrNoneRan` (killed, errored, syntax error, timed out, no mutant, a count given as a string, a missing count), `testCanaryFailsWhenItCannotReadTheSummary` (missing, cut off, counts under another key), `testCanaryIgnoresArguments` |
| **No mutant killed by the test harness** | `testLogCheckPassesWhenNoMutantWasKilledByTheHarness`, `testLogCheckFailsWhenAMutantWasKilledByTheHarness` (`ClassIsFinalException`, `cannot extend final class`, `Error in bootstrap script`), `testLogCheckFailsWhenTheLogIsMissingOrEmpty`, `testLogCheckIgnoresArguments` |
| **Escaped mutants go to the job log, not to GitHub annotations** | `testInfectionRunsWriteNoGitHubAnnotations` (the canary and the real run), `testMutationRunListsEveryEscapedMutant` |

### Unit Suite Configuration (`tests/unit/UnitSuiteConfigTest.php`)

| Feature | Tests |
|---------|-------|
| **A PHPUnit deprecation fails the unit suite** | `testAPhpunitDeprecationFailsTheUnitSuite` (`failOnPhpunitDeprecation` in `phpunit.xml.dist`, known to PHPUnit 11.5 to 13) |
| **On PHPUnit 13, a PHPUnit notice fails the unit suite** | `testAPhpunitNoticeFailsTheUnitSuiteOnTheJobThatRunsPhpunit13` (CI's PHP 8.5 job passes `--fail-on-phpunit-notice`, which catches a test double that sets no expectations; PHPUnit 11 rejects `failOnPhpunitNotice` in the XML file) |

### Functional Suite Configuration (`tests/unit/FunctionalSuiteConfigTest.php`)

| Feature | Tests |
|---------|-------|
| **An undefined or pending step fails the functional suite** | `testUndefinedAndPendingStepsFailTheFunctionalSuite` (`tests/functional/behat.php` turns on strict result interpretation: Behat 4 registers steps from attributes only, so a step left as a doc-comment annotation is undefined, and without strict interpretation the run still exits 0) |

## Functional Tests (Behat)

### Stream Setup (`tests/functional/features/nats_setup.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Stream creation with max age** | Setup NATS stream with max age configuration (native PHP serializer, igbinary) |
| **Existing stream handling** | Setup command handles existing streams gracefully (native PHP serializer, igbinary) |
| **Memory storage** | Setup NATS stream with memory storage |
| **Per-subject message limit** | Setup NATS stream with max messages per subject configuration |
| **Multi-subject streams** | Setup command merges subjects for transports sharing one stream |
| **Unavailable server** | Setup command fails gracefully when NATS is unavailable |
| **Full message flow** | Complete message flow - send, check stats, consume, verify (native PHP serializer, igbinary) |
| **Partial consumption** | Partial message consumption with multiple consumers (native PHP serializer, igbinary) |
| **High-volume processing** | High-volume message processing with file output verification (native PHP serializer, igbinary) |

### Stream Limits (`tests/functional/features/nats_stream_limits.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Max bytes** | Setup stream with max bytes limit |
| **Max messages (create)** | Setup stream with max messages limit |
| **Max messages per subject (create)** | Setup stream with max messages per subject limit |
| **Max messages (update)** | Update existing stream preserves max messages limit |
| **Max messages per subject (update)** | Update existing stream preserves max messages per subject limit |
| **Message eviction** | Stream evicts oldest messages when max messages limit is exceeded |
| **Operator-set consumer limit (update)** | Update a stream an operator created with a consumer limit |

### Auto Setup (`tests/functional/features/nats_auto_setup.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Lazy provisioning on first send** | Auto setup provisions the stream and consumer on the first send |
| **Re-provisioning after a consumer is removed** | A worker started after the consumer was removed re-provisions it |
| **Disabled by default** | Without auto setup the stream is not provisioned implicitly |

### Consumer (`tests/functional/features/nats_consumer.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Custom consumer name** | Send and consume messages with a custom consumer name |
| **Consumer name verification** | Custom consumer name is registered in JetStream |
| **Shared-consumer load balancing** | Many workers sharing one durable consumer each process a distinct share (5 consumers / 100 messages, each processed exactly once) |

### Batching (`tests/functional/features/nats_batching.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Batch consumption** | Consume messages with batching of 5 |
| **Partial batch** | Consume fewer messages than batch size |

### Retry Handlers (`tests/functional/features/nats_nak.feature`, `nats_term.feature`)

| Feature | Scenarios |
|---------|-----------|
| **NAK retry** | Message retry handled by NATS via NAK |
| **NAK retry with Symfony's default retry strategy (#90)** | A message that fails once is redelivered with Symfony's default retry strategy |
| **A message that cannot be decoded, nats mode (5.9.1, 5.10.0)** | A message that cannot be decoded is TERMed in nats mode, so no worker gets it again (igbinary but no envelope; two workers of 3 s: delivered once, nothing redelivered or pending; on Symfony Messenger 8.1 and later both workers run to their time limit, before 8.1 the first stops on the decode error and the second runs; on 5.9.0 with Messenger 8.1.9 it was delivered 3769 times) |
| **An unrecoverable failure, nats mode (5.10.0)** | An unrecoverable failure runs once in nats mode (`max_deliver: 3`, Symfony's default retry strategy: 1 attempt; 3 on 5.9.x) |
| **TERM failure** | Failed message routed to Symfony failure transport via TERM (the consumer warns once that Symfony's retry was published without its delay) |
| **TERM failure with the igbinary serializer (retry and failure-transport re-sends)** | Failed message routed to Symfony failure transport via TERM with the igbinary serializer |
| **A message that cannot be decoded, symfony mode (5.10.0)** | A message that cannot be decoded is settled in symfony mode (igbinary but no envelope, with a failure transport; two workers of 3 s leave nothing pending; on Symfony Messenger 8.1 and later both run to their time limit, before 8.1 the first stops on the decode error and the second runs) |

### Symfony's Retries Through NAKs (`tests/functional/features/nats_retry_via.feature`)

Symfony's worker (`messenger:consume`) on a transport with `retry_via=nak`, the native PHP serializer,
`retry_strategy: { max_retries: 2, delay: 100, multiplier: 2 }` and a failure transport. Not tagged `@delayed`: a NAK
with a delay works on every supported server, so the oldest-server job runs the feature too. With `retry_via: copy`
the first two scenarios fail, on a stream that holds 3 and 2 messages.

| Feature | Scenarios |
|---------|-----------|
| **A message that keeps failing** | A message that keeps failing is retried through NAKs, then given up on with one failure copy (3 attempts, 1 message in the failure transport, 1 in the stream, and no warning that Symfony's retry strategy is on) |
| **A message that fails once** | A message that fails once is handled on its NAK retry (nothing in the failure transport, 1 message in the stream) |
| **An unrecoverable failure** | An unrecoverable failure runs once and leaves one failure copy |
| **A consumer limit the option does not say** | A consumer whose max_deliver the option does not say still gets the retry of its last delivery (the consumer changed to `max_deliver: 2` behind the transport, the option unset: 3 attempts, 1 message in the failure transport, 2 in the stream, one warning; on 5.9.0 the message was gone after 2 attempts) |

CI's lowest leg runs these on Symfony Messenger 7.4.21, whose worker rejects a message it sent for retry, and the PHP
8.5 leg on a release whose worker acknowledges it.

### Delayed Messages (`tests/functional/features/nats_delayed.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Scheduled delivery** | Delayed messages are delivered after the scheduled time |
| **Not delivered early** | Delayed messages are not available to the consumer before the scheduled time |
| **Larger delayed batch** | A larger batch of delayed messages all arrive after the scheduled time (10 messages) |
| **Delayed load balancing** | Delayed messages are load-balanced across multiple consumers (12 messages / 3 consumers) |
| **Interest stream, no interest in the delayed subjects** | scheduled_messages on an interest stream nothing has interest in the delayed subjects of is refused (`messenger:setup-transports` fails naming it, before it creates the stream, and passes once `scheduled_messages` is turned off; on 5.9.0 the setup succeeded, and on the first form of 5.9.1 it created the stream before it refused it); A worker refuses to pull from an interest stream nothing has interest in the delayed subjects of (a stream made outside the transport: `messenger:consume` fails naming it) |
| **Interest stream with a pinning consumer** | Delayed messages on an interest stream are delivered while a consumer has interest in the delayed subjects (a durable consumer on `{topic}.delayed.>` nobody pulls from; 3 messages with 2000 ms delay all consumed) |

### Large Messages (`tests/functional/features/nats_large_messages.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Single-consumer round-trip** | Round-trip large messages through a single consumer (native PHP + igbinary serializers, 128 KB) |
| **Multi-consumer load balancing** | Large messages are load-balanced across consumers, each processed exactly once (64 KB) |

### TLS (`tests/functional/features/nats_tls.feature`, `nats_mtls.feature`)

| Feature | Scenarios |
|---------|-----------|
| **TLS connection** | TLS server connection with native PHP serializer, TLS server connection with igbinary serializer |
| **mTLS connection** | mTLS server connection with client certificate |

### NKey Authentication (`tests/functional/features/nats_nkey.feature`)

The NKey user is in `tests/nats/nats.conf`, `nats-tls.conf` and `nats-mtls.conf`. Its key is generated at `composer nats:start`,
see `tests/nats/auth/README.md`.

| Feature | Scenarios |
|---------|-----------|
| **NKey seed, no user or password** | Messages are sent and consumed by a user that authenticates with an NKey seed |
| **The other forms the README shows** | A seed read from the environment logs in, in each form the README's configuration shows (`%env(NATS_NKEY_SEED)%`, `%env(trim:file:NATS_NKEY_SEED_FILE)%`, `%env(default::NATS_NKEY_SEED)%`); A seed in the DSN query logs in as well; An nkey option that repeats the seed's public key logs in |
| **TLS and mTLS** | A seed logs in over TLS; A seed logs in over mTLS, next to the client certificate |
| **Every new connection signs a new nonce** | A worker that logs in with a seed keeps consuming across a server restart (the worker holds a connection before the restart, and the scenario checks that it reconnected) |
| **The server checks the seed** | A seed the server does not know is refused by the server (the server's `Authorization Violation`) |
| **A server that sends no nonce** | A seed on a server that sends no nonce is refused by the client |
| **Refusals when the transport is built, seen from the console** | Credentials that need or exclude a seed are refused when the transport is built, on every command (`messenger:setup-transports`, `messenger:stats` and `messenger:consume` each exit non-zero with the error, for a JWT and no seed, a public NKey and no seed, and a seed next to a password) |
| **Values that cannot log in, and no seed in the output** | A value that cannot log in is refused when the transport is built, and no seed is printed (an empty seed, a mistyped one, an account's seed, a seed in `nkey`, another user's public key, a seed after a second `?` in the DSN) |

### JWT Authentication (`tests/functional/features/nats_jwt.feature`)

Runs against `nats-jwt` (port 4229, `tests/nats/nats-jwt.conf`), a test server in operator mode. Its credentials are generated
at `composer nats:start`, see `tests/nats/auth/README.md`.

| Feature | Scenarios |
|---------|-----------|
| **`jwt` with `nkey_seed`** | Messages are sent and consumed by a user that logs in with a JWT and its seed; A JWT and its seed read from the environment log in, in the form the README's configuration shows |
| **A bearer JWT** | A bearer JWT logs in with its seed |
| **Every new connection signs a new nonce** | A worker that logs in with a JWT keeps consuming across a server restart (the worker holds a connection before the restart, and the scenario checks that it reconnected) |
| **A value that is not a JWT** | A value that is not a JWT is refused when the transport is built, and no seed is printed (the seed, and the text of a `.creds` file, in the `jwt` option) |
| **What the server refuses** | A JWT with another user's seed is refused by the server; A seed with no JWT is refused by the server; An expired JWT is refused by the server (each the server's `Authorization Violation`) |
| **An account without JetStream** | A user of an account without JetStream gets the server's error from setup |

### Token Authentication (`tests/functional/features/nats_token.feature`)

Runs against `nats-token` (port 4230, `tests/nats/nats-token.conf`), which has a server of its own because `nats-server` refuses a token next to a list of users.

| Feature | Scenarios |
|---------|-----------|
| **`token`** | Messages are sent and consumed by a client that logs in with a token |
| **The server checks the token** | A wrong token is refused by the server (the server's `Authorization Violation`) |

### Type Coercion (`tests/functional/features/nats_type_coercion.feature`)

| Feature | Scenarios |
|---------|-----------|
| **DSN query-string coercion** | Numeric options supplied via the DSN query string are coerced and applied (max_age, max_bytes, max_messages) |

### Synchronous Acknowledgement (`tests/functional/features/nats_ack_sync.feature`)

| Feature | Scenarios |
|---------|-----------|
| **ack_sync** | Messages are consumed with synchronous (double) acknowledgement and the queue drains to zero |

### NATS-native Redelivery Cap (`tests/functional/features/nats_max_deliver.feature`)

| Feature | Scenarios |
|---------|-----------|
| **max_deliver** | NATS stops redelivering a poison message after `max_deliver` attempts (no infinite loop); with `max_retries: 0` the consumer logs no warning about Symfony's retry strategy (#94) |
| **Symfony's retry strategy ignored in nats mode** | Symfony's retry strategy is ignored with the NATS retry handler (default 3 retries, `max_deliver: 3`, exactly 3 attempts); the consumer's log, on stderr through FrameworkBundle's default logger, has the warning that Symfony's retry strategy is on exactly once (#94) |
| **A nats-mode stream that loses what `max_deliver` stops** | In nats mode, setup warns about an interest stream when the server deletes a message stopped at max_deliver (`max_deliver: 3`, interest retention: one warning on a standalone server before 2.12.9 or on 2.14.0, as the oldest-server job runs, none on a later one) |

### Keepalive (`tests/functional/features/nats_keepalive.feature`)

| Feature | Scenarios |
|---------|-----------|
| **`messenger:consume --keepalive`** | A message handled for longer than ack_wait is not redelivered while keepalive runs (`ack_wait: 3`, a 7-second handler, `--keepalive=1`, a second consumer that would receive a redelivery; handled exactly once) |

### Duplicate Protection (`tests/functional/features/nats_deduplicate.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Deduplication id** | A message dispatched again with the same deduplication id is stored once (3 messages, then the first again as a new envelope: 3 stored, 3 consumed) |
| **`deduplicate` option** | Distinct messages are all stored with the deduplicate option (5 stored, 5 consumed) |
| **Two transports on one stream** | A message routed to two deduplicating transports on one stream is stored for each (2 messages in the stream; in 5.4.0 the second copy was dropped) |

### Idle Connection Check (`tests/functional/features/nats_idle_connection.feature`)

Runs against `nats-stale` (port 4225, `tests/nats/nats-stale.conf`), a test server that drops a client which leaves more than one PING unanswered, about three seconds after it went quiet.

| Feature | Scenarios |
|---------|-----------|
| **`ping_after_idle`** | A message sent after the server dropped the idle connection goes out (one process sends, idles 5 s, sends again: both stored; on 5.1.0 the second send failed with 'Stale Connection') |
| **Control, check off** | Without the check the message sent after the server dropped the connection fails (`ping_after_idle: 0`: the second send fails, 1 stored), which shows the server really dropped it |

## Examples (`examples/`, `composer examples`)

Runnable scripts against a live server, run in CI after the functional suite, on the latest and the oldest
supported NATS. Each prints `OK ...` when what it shows held, and fails otherwise. The ones on the test cluster first wait
until a stream with three replicas can be created through each server (`waitForClusterServer()`), as a server that just
rejoined can still refuse one on NATS 2.10 after it answers JetStream requests.

| Example | Verifies |
|---|---|
| `send-and-consume.php` | 3 messages sent are waiting, then received in order and acknowledged, leaving the queue empty |
| `duplicate-protection.php` | an application id sent twice is stored once; the envelope `send()` returned, sent again, is dropped; its retry (`RedeliveryStamp` 1) is stored |
| `nats-retry.php` | with `retry_handler: nats`, `nak_delay: 1` and `max_deliver: 3`, Symfony's Worker and retry listener with FrameworkBundle's default strategy (3 retries) over a message whose handler fails once: the handler runs exactly twice, at least 0.9 s apart, the stream holds the one message (the retry was not published) and the consumer has nothing pending; fails on 5.7.1 with Symfony Messenger 7.4.22 / 8.1.10 or later, where the message was acknowledged after its first failure (#90); the logger the transport was given received exactly one warning, that Symfony's retry strategy is on, with the text that names `max_retries: 0` and `retry_via: nak`; then, in nats mode through Symfony's Worker, a message that is igbinary but no envelope is delivered once, nothing is pending or redelivered and both workers run: on Symfony Messenger 8.1 and later the worker fails it once as the decoding failure (`UnrecoverableMessageHandlingException` from 8.1.11, the `MessageDecodingFailedException` itself before) and the transport logs nothing, before 8.1 the transport TERMs it and the first worker stops on the decode error, as with Symfony's own transports, and the second runs; then an `UnrecoverableMessageHandlingException` with `max_deliver: 3` runs once and is TERMed, not redelivered |
| `retry-via-nak.php` | with `retry_via: nak`, Symfony's Worker with its retry listener and its failure-transport listener (an `InMemoryTransport`), over five messages. One that keeps failing under `MultiplierRetryStrategy(3, 200, 2, 500)` runs 4 times at retry counts 0 to 3, each retry no earlier than its delay (0.2, 0.4 and the capped 0.5 s) after the failure before, `willRetry()` true three times and then false; then one `MSG_TERMINATED` advisory and no `MAX_DELIVERIES`, 1 failure-transport copy, 1 message in the stream, nothing waiting for an acknowledgement, and no warning logged. One that fails once is handled on its retry. A `RecoverableMessageHandlingException(retryDelay: 700)` is retried no earlier than 0.7 s later. An unrecoverable failure runs once and leaves 1 failure-transport copy. A strategy without a delay is retried within a second |
| `retry-via-nak-edge-cases.php` | with `retry_via: nak`: a delivery nobody settled comes back after `ack_wait: 1` with retry count 1; a payload that is igbinary but no envelope fails once as unrecoverable on Symfony Messenger 8.1.11 and later (1 run, a TERM and 1 failure-transport copy, nothing logged), goes through the retry strategy on 8.1.0 to 8.1.10 (3 runs at counts 0, 1 and 2, then a TERM and 1 copy), and before 8.1 is TERMed at once, with `get()` throwing `MessageDecodingFailedException`, one `MSG_TERMINATED` and no later delivery; a payload Symfony's `PhpSerializer` cannot decode goes through the retry strategy on Messenger 8.1 and later (3 runs, then a TERM and 1 failure-transport copy) and is TERMed at once before 8.1, with `get()` throwing, read from `Composer\InstalledVersions`; the `reject()` of Messenger 7.4.21, 8.1.9 and earlier, made by hand after the retry copy was sent, brings the message back no earlier than its 0.3 s delay with retry count 1 and 1 message in the stream; a message published with retry count 1 runs 3 times with `max_retries: 3`, at counts 1, 2 and 3; the builder refuses `retry_via` with `retry_handler: nats`, and `nak_delay` and `backoff` with `nak`, each with its message; a consumer another setup limited to 2, with the option unset, runs 3 times at counts 0, 1 and 2 with 1 failure-transport copy and one warning (2 runs on 5.9.0) |
| `retry-via-nak-past-max-deliver.php` | with `retry_via: nak` and `max_deliver: 2`, under Symfony's Worker: a message that keeps failing with `max_retries: 3` runs 4 times at retry counts 0 to 3, the second retry at once (the copy of the last delivery) and the third no earlier than 0.8 s after its failure (a NAK of the copy's first delivery), then 1 failure-transport copy, 2 messages in the stream, a `MSG_TERMINATED` advisory, no `MAX_DELIVERIES`, and one warning that the copy went out without its 400 ms delay; a message whose handler throws a `RecoverableMessageHandlingException` until its fifth run, with `max_retries: 1`, is handled on the fifth at retry count 4, with nothing in the failure transport |
| `retry-via-nak-server-behaviour.php` | the Worker's part made by hand (the retry copy with its `DelayStamp`, then `ack()`): a `DelayStamp` of `PHP_INT_MAX` with `ack_wait: 1` is not redelivered within 2.5 s and still waits for its acknowledgement; a 4 s retry on a stream with `stream_max_age: 2` leaves the stream empty after 3 s and is never redelivered; on a consumer set up with `backoff` 1, 2, 3 s by a transport without `nak`, three retries of 0.5 s come back no earlier than 0.5, 1.5 and 2.5 s later |
| `retry-via-nak-across-a-restart.php` | a delivery NAKed with a 12 s delay (`ack_wait: 2`), `docker restart nats_test` one second later: after the restart the consumer still shows 1 delivery waiting for its acknowledgement and the stream 1 message, and a new transport receives the message exactly once, with retry count 1, no earlier than 10.9 s after the NAK. Prints `SKIP` when `NATS_DSN` is set, as `reconnect.php` does |
| `retry-via-copy-or-nak.php` | a message that fails once, under Symfony's Worker, with `retry_via: copy` and with `nak`: a second consumer on the subject receives 2 messages and the stream stores 2 under `copy`, 1 and 1 under `nak`; with `max_ack_pending: 1` and a retry that waits 1.5 s, the next message is handled within a second of the failure under `copy` and no earlier than 1.45 s after it under `nak`; a 0.3 s retry runs within a second as a copy and no earlier than 0.29 s later as a NAK, and as a copy with `scheduled_messages` on a server from NATS 2.12 on, which the example leaves out on an older one |
| `delayed-messages.php` | reads the server's version. From NATS 2.12 on: with `scheduled_messages`, a message sent with a `DelayStamp` of 1.5 s arrives no earlier than 1.45 s later, after one sent without a delay. On an older server: `setup()` with `scheduled_messages` fails with "The 'scheduled_messages' option requires NATS Server >= 2.12" and creates no stream; on a stream set up without the option, a delayed send fails with "The delayed message was not sent" and stores nothing, and a send without a delay arrives. CI's oldest-server job runs that branch. From NATS 2.12 on it also shows a stream with interest retention: `setup()` refuses `scheduled_messages` while no consumer has interest in the delayed subjects, and with a durable consumer on them that nobody pulls from it goes on and a message delayed 1.5 s arrives no earlier than 1.45 s later |
| `request-timeout.php` | against a `no_ack` stream, a send with `request_timeout: 1` gives up after about 1 s although the message is stored, and sending it again with the same deduplication id stores nothing more |
| `keepalive.php` | with `keepalive()` called from a SIGALRM handler every second, a message handled for 5 s on `ack_wait: 2` is not redelivered to a second worker (on 5.2.1 the first alarm broke the worker), and the count reaches 0 after the plain ACK |
| `connection-checks.php` | sends after idling past `ping_after_idle` and after `close()` both succeed |
| `jwt-login.php` | on the operator-mode server, the user's JWT and seed run `setup()`, a send, the queue depth, a get and an ack; the JWT with another user's seed and an expired JWT get `Authorization Violation`; the JWT alone and a seed in the `jwt` option are refused when the transport is built |
| `nkey-login.php` | on the plain server, the seed alone runs the same; a seed the server does not list gets `Authorization Violation`; the public key alone and a seed next to a password are refused when the transport is built. Prints `SKIP` when `NATS_DSN` is set, as the examples then run elsewhere and the NKey user exists only on the test server |
| `token-login.php` | on the token server, the token runs the same; a wrong token gets `Authorization Violation` |
| `reconnect.php` | with `reconnect: true`, `auto_setup` and a memory stream, `docker restart nats_test` during a pull: that pull and every pull until the restart is over end empty without an exception (`409 Server Shutdown`, then the client's recovery), the message sent once the server is back is stored in the recreated stream and received, and the logger the transport was given received the client's `Reconnected` |
| `cluster-failover.php` | on the three-server test cluster, an R3 stream; a worker with `reconnect: true` whose DSN names only the stream's leader receives 2 of 4 messages, `docker stop` stops that server, and the worker receives the other 2 and one it sends afterwards without an exception, through a server it learned from the cluster; another server leads the stream; the logger received `Reconnected` |
| `handler-failing-during-failover.php` | on the three-server test cluster, once per retry mode: an R3 stream with `nak_delay: 1` and `ack_wait: 30`; Symfony's Worker and retry listener with FrameworkBundle's default strategy, the worker's DSN naming only the stream's leader with `reconnect: true`; the handler stops that server with `docker stop` and fails on its first attempt: it runs again on the same worker within 10 s of the failure (in nats mode not before 0.9 s), nothing is pending or undelivered and the logger received `Reconnected`. Fails on 5.7.1 in nats mode, where the NAK went into the connection the server had closed and the message came back only after `ack_wait` (1 attempt within the worker's 15 s), and with client 2.27.2 in symfony mode, where the retry copy went into that connection and its publish ended the worker with `TimeoutException` |
| `handler-succeeding-during-failover.php` | the same setup without the retry listener, once per retry mode; the handler stops the stream leader with `docker stop` and succeeds; the worker pulls on for 3 s: the message was handled once, nothing is pending or undelivered (the ACK reached the cluster) and the logger received `Reconnected`. Fails with client 2.27.2, where the ACK went into the closed connection and stayed pending until `ack_wait` |
| `nats-retry-on-a-cluster.php` | on the three-server test cluster, `retry_handler: nats` with `max_deliver: 2` and three replicas: a message both of whose deliveries are rejected, among three handled ones, is stored right after; on an interest stream `setup()` warns once naming a clustered server and NATS deletes the message within 30 s; on a work-queue stream from NATS 2.12.5 on it stays for 12 s and nothing is logged (before 2.12.5 the warning and the deletion are expected) |
| `stream-placement.php` | on the three-server test cluster (two servers tagged `disk:ssd`, one `disk:hdd`): `stream_replicas: 2` with `stream_placement_tags: disk:ssd` puts the replicas on the two ssd servers; `stream_placement_cluster` set alone stores `{cluster, tags}` with the live tags and the replicas stay; one replica on `disk:hdd` in one update fails `setup()` with "can not move and scale" and the staging advice, leaving the stream as it was; staged (replicas first, then the tags) the stream moves to the hdd server with its 3 messages. The staged step from 2 replicas to 1 is where NATS moves the consumer, and on NATS 2.10 a consumer request of that `setup()` could meet the moment without a consumer leader (err_code 10008), which failed the example in CI before `setup()` asked again |

The cluster examples run against the cluster `composer nats:start` starts next to the single servers
(`tests/nats/nats-cluster*.conf`, ports 4226 to 4228, see `examples/README.md`), in both CI jobs. The login examples
run against the server that knows their credentials, and print `SKIP` when it is not running or the credentials
`composer nats:start` generates are missing. CI runs them with no `NATS_DSN`, and strict mode turns a `SKIP` into a failure.

### Automatic Reconnect (`tests/functional/features/nats_reconnect.feature`)

The main test server is restarted with `docker restart` while a process holds a connection to it, with `reconnect: true`
(#81). A graceful stop ends every pull in flight with `409 Server Shutdown` and closes every connection; a memory stream
does not survive it.

| Feature | Scenarios |
|---------|-----------|
| **A worker polls through a server restart without exiting** | A worker keeps consuming across a server restart (the consumer holds a connection before the restart, reconnects, and consumes the five messages sent after it; the step no longer pauses after the connection, so on CI's fast docker the worker's pull can land in the last moments of a graceful stop on NATS 2.15, whose 503 the transport reads as an empty batch since #106) |
| **A worker polls through a server that leaves in lame duck mode** | A worker keeps consuming across a server that leaves in lame duck mode (SIGUSR2 sent from inside the container, as `docker kill` would mark it stopped by hand and keep docker from starting it again; the server stops JetStream, keeps the connections for its 10 s grace period, during which every pull gets 503, closes them and exits, and docker starts it again; request_timeout is 3 s, below the grace period, so that a transport waiting out a question about the 503 would end the worker first. Fails with `JetStream pull request ended with status 503` on the transport that asked CONSUMER.INFO itself with client 2.28, #106) |
| **The client's own reconnect is used by the next send, and auto_setup recreates the memory stream the restart lost** | A message sent after a server restart goes out on the reconnected connection and the memory stream is recreated (`ping_after_idle` 2 s, so the send after the quiet period checks the connection first and finds the client recovering it; the stats show the one message the recreated stream holds) |

### Stream Placement (`tests/functional/features/nats_stream_placement.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Placement tags (create)** | Setup stream with placement tags supplied via the DSN |
| **Placement tags (update)** | Update of an existing stream applies the configured placement tags |
| **Placement preserved when unset** | Update of an existing stream keeps its placement when the option is unset (a JetStream update that omits `placement` clears it; the transport echoes the live value; the max age assertion proves the update ran) |
| **Placement merged field by field (update)** | Configured tags keep the cluster pin of an existing placement, A configured cluster keeps the tags of an existing placement (the stream is created out of band with both halves; a standalone server stores the cluster name without acting on it) |
| **Equal tag set keeps the stored list (update)** | The same tags in another order keep the stored tag list |

## Mutation Testing

Mutation testing is configured via [Infection](https://infection.github.io/) (`infection.json5`) and run
with `composer test:mutation`. It enforces a minimum MSI of 90% and a minimum covered MSI of 95%; CI runs it
on the PHP 8.5 job and daily (`.github/workflows/mutation.yml`). The suite scores 98.7% MSI and covered
MSI: 1625 of 1647 mutants killed, none timed out, with 100% mutation code coverage.

Scores reported before, 100% among them, were not real. Infection runs each mutant in a PHPUnit process
whose bootstrap puts its include interceptor on `file://`; the interceptor dropped the wrapper BypassFinals
puts on top of it, so the client's final classes could not be doubled there, every test that doubles one
failed, and Infection counted the mutant as killed. `tests/bootstrap.php` now takes the interceptor's file
swap over (`MutationTestingBootstrapTest`). In a mutant process the mutated class is therefore compiled from
`.infection/infection/mutant.*.infection.php`, with the original line numbers, so a breakpoint set in `src/`
is not hit there.

Two checks around the run keep the score honest; CI runs both:

- **Before it, `composer test:mutation:canary`** runs Infection with `--noop` and the `TrueValue` and
  `FalseValue` mutators. Its mutants (67 today) change nothing, so every one must escape;
  `scripts/check-mutation-canary.php` fails when one is detected, when none ran, or when the summary is
  missing or malformed. On the old bootstrap it detected 42 of 46.
- **After it, `composer test:mutation:check-log`** (`scripts/check-mutation-log.php`) fails when
  `infection.log` shows a mutant that failed with `ClassIsFinalException`, `cannot extend final class` or
  `Error in bootstrap script`, which the harness causes and no test does. `test:mutation` runs with
  `--log-verbosity=all`, so the log holds every mutant's output.

Both Infection runs pass `--logger-github=false`. On GitHub Actions Infection otherwise turns every escaped
mutant into a warning annotation on its source line: the canary's, which all escape by design, and the
survivors below, on every run and in every pull request. The real run lists every escaped mutant in the job
log instead (`--show-mutations=max`), and CI uploads `infection.log` as the `infection-log` artifact when a
mutation step fails.

### Surviving mutants

Of the 22 mutants that survive:

- **17 are equivalent**: no input makes them behave differently.
- **2 make no difference with the real client**: its acknowledgement calls do not read the `sid` of the
  message `buildAckMessage()` gives them. A test could kill them only by asserting on the `sid` its client
  double receives, which would pin a detail rather than behaviour.
- **3 are caught by the suite where Infection cannot see it**: only a child process can disable or replace a sodium
  function, and Infection applies a mutant in the test's own process, so the child loads the original class. Made by
  hand, each fails a plain `composer test`.

| Mutant | Why it survives |
|---|---|
| `connectionUsable()`: `$ping->ignore()` removed | `await()` subscribes to the PING, which marks it handled even when the wait gives up |
| `buildAckMessage()`: `sid` 1 or -1 instead of 0 (2 mutants) | The client's ack, ackSync, nak, nakWithDelay, term and inProgress read only the reply subject, so only an assertion on the `sid` itself would see the change |
| `buildUpdatedStreamConfiguration()`: a missing `duplicate_window` read as -1 or 1 instead of 0 (2 mutants) | A positive max age is at least 10^9 nanoseconds, so none of these exceeds it |
| `buildUpdatedStreamConfiguration()`: the window clamped when it equals the max age, too | Clamping it then writes the same value |
| `NatsTransportConfiguration`: the defaults of `batching` (0), `stream_max_age` (-1) and `stream_replicas` (0) (3 mutants) | `max()` clamps each to what the original default gives |
| `assertPositiveNumber()`, `assertNonNegativeNumber()`: `ceil()` or `round()` instead of `floor()` (4 mutants) | All three return the number itself exactly when it is whole |
| `assertDuplicateWindowNotExceedingMaxAge()`: a null max age read as -1 instead of 0 | Both mean no age limit there |
| `send()`: a missing `DelayStamp` read as -1 instead of 0 with `retry_via: nak` | `nakForRetry()` sends a plain NAK for any delay of 0 or below |
| `warnIfNatsModeLosesWhatMaxDeliverStops()`: a stream info without a replica count read as 0 replicas instead of 1 | It only asks whether there is more than one replica, which 0 and 1 answer alike (2, the other mutant, is killed by a work-queue stream without a count on 2.12.4) |
| `serverVersionIsAtLeast()`: the version pattern without its `^` | Servers report a version that starts with its number, or with `v`, so the pattern finds the same release either way |
| `assertTheStreamKeepsDelayedMessages()`: the sample delayed subject without its UUID | A filter tells `{topic}.delayed.<uuid>` from `{topic}.delayed.` (an empty last token) only by naming that token, and NATS refuses a subject with an empty token |
| `requiredString()`: `&&` instead of `\|\|` | `parseDsn()` already requires a host, and `parse_url()` never gives an empty one |
| `resolveNkeySigner()`: `\Error` removed from the catch, and `\|\|` for either `&&` of the condition on the seed advice (3 mutants) | Between them `testADisabledSodiumFunctionIsRefusedWhenTheTransportIsBuiltWithoutChainingTheError` and `testAFailureInsideSodiumIsRefusedWithoutSayingWhatASeedLooksLike` fail on each, but they run in a child process, which the mutant does not reach |

Before PR #98 dealt with them, 32 survived. Two of those, the round-up of a delayed message's delivery time
compared with -1 or without its cast, are killed now: `send()` takes the delivery time from `deliveryTime()`, which
`testSendDelayedMessageDueOnAWholeSecondIsScheduledForThatSecond` replaces with a time on a whole second. The
other fifteen (fourteen equivalent, and the stream's subjects, which made no difference with the real client) are
gone because the code they mutated said the same thing twice, and now says it once, with the same
behaviour: `send()`'s `?? 0` for a missing `DelayStamp` (`null > 0` is false as well), its `'+' . ... . ' milliseconds'`
(a `sprintf()` now) and the `(string)` cast of a header name (PHP makes the key an int either way); `get()`'s check
for a message without headers (`NatsHeaders::fromWireBlock()` returns `[]` for both); `streamReplicas() > 0`
(never below 1) and `streamMaxAgeSeconds() > 0` (0 seconds is 0 nanoseconds); the stream's subjects read from
`StreamInfo` after the config entry it is built from; the empty-segment check after `trim()` in
`parseStreamAndTopic()` (3 mutants); the early returns for an unset value in `assertStreamDescriptionLength()`,
`assertDuplicateWindowNotExceedingMaxAge()` and `toNullableString()`; and the int, float and string checks before
`is_numeric()` and the `(float)` cast in `assertBackoff()` (2 mutants).

### The mutant that used to time out

`whileTheClusterSettles()` without `return $request();` never returns and pauses forever. It used to end with
Infection's 60-second per-mutant timeout, which counts as detected. The unit tests' transport doubles now fail a
test once `pause()` ran more often than `setup()` ever pauses (60 real pauses, 1000 recorded ones), so the
mutant fails a test within seconds and counts as killed. `tests/Support/ClientInjectedNatsTransport.php` has the
same 60-pause limit since #106: the tests of an `auto_setup` pull reach `setup()` through it, and are among the
first Infection runs for that line, so without the limit the mutant timed out again. The 60-second `timeout` in
`infection.json5` stays, for any other loop that never ends.

## README Example Coverage

This section maps every code example in `README.md` to the test(s) that verify it works.

### DSN Format Examples

| README Example | Tests |
|---|---|
| `nats-jetstream://localhost/my-stream/my-topic` (default port) | `testReadmeDsnExamplesParseSuccessfully[README: default port]`, `createTransport_WithDefaultPort_ParsesDsnCorrectly`, `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent` |
| `nats-jetstream://localhost:5000/my-stream/my-topic` (custom port) | `testReadmeDsnExamplesParseSuccessfully[README: custom port]`, `createTransport_WithValidDsn_ReturnsNatsTransportInstance` |
| `nats-jetstream://user:password@localhost:4222/...` (auth) | `testReadmeDsnExamplesParseSuccessfully[README: with authentication]`, `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent` |
| `nats-jetstream://localhost/...?consumer=worker&batching=10` (query) | `testReadmeDsnExamplesParseSuccessfully[README: with query parameters]`, `testReadmeQueryParamDsnProducesCorrectValues`, `createTransport_WithQueryParams_ParsesConfigCorrectly` |
| `nats-jetstream+tls://localhost:4222/...` (TLS) | `testReadmeDsnExamplesParseSuccessfully[README: TLS scheme]`, `testBuildWithTlsSchemeUsesTlsServerProtocol`, `testConstructorWithTlsDsnInitializesTransport` |
| `nats-jetstream://localhost/events/orders` (multi-subject) | `testReadmeDsnExamplesParseSuccessfully[README: multi-subject orders]`, `testReadmeMultiSubjectOptionsAreAccepted` |
| `nats-jetstream://localhost/events/payments` (multi-subject) | `testReadmeDsnExamplesParseSuccessfully[README: multi-subject payments]`, `testReadmeMultiSubjectOptionsAreAccepted` |
| `nats-jetstream://localhost/...?scheduled_messages=true` (delayed) | `testReadmeDsnExamplesParseSuccessfully[README: scheduled messages DSN]`, `testReadmeScheduledMessagesDsnEnablesFeature` |
| `nats-jetstream://localhost:4222/my-stream/my-topic?retry_via=nak` (Retrying through a NAK) | `testReadmeDsnExamplesParseSuccessfully[README: retry_via nak]`, `testRetryViaIsCopyUnlessSetToNak`, Behat feature `nats_retry_via.feature` |
| `nats-jetstream://localhost/fast-stream/fast-topic` | `testReadmeDsnExamplesParseSuccessfully[README: fast transport]`, `testReadmeBatchingExamplesAreAccepted` |
| `nats-jetstream://localhost/bulk-stream/bulk-topic` | `testReadmeDsnExamplesParseSuccessfully[README: bulk transport]`, `testReadmeBatchingExamplesAreAccepted` |
| `nats-jetstream://localhost/audit-stream/audit-topic` | `testReadmeDsnExamplesParseSuccessfully[README: audit transport]`, `testReadmeAuditTransportOptionsAreAccepted` |
| `nats-jetstream+tls://nats.internal:4222/orders/orders.created` (Using a NATS Cluster) | `testReadmeDsnExamplesParseSuccessfully[README: cluster transport]`, `testReadmeClusterOptionsAreAccepted` |
| DSN template `nats-jetstream://[user:password@]host:port/stream-name/topic-name` | `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `createTransport_WithValidDsn_ReturnsNatsTransportInstance` |

### PHP Code Examples

Every fenced ` ```php ` block in `README.md` is additionally syntax-checked by
`ReadmeExamplesTest::testReadmePhpExampleIsSyntacticallyValid` (and the count is pinned by
`testReadmeContainsThePhpExamplesWeExpect`), so a snippet that stops being valid PHP fails CI.

| README Example | Tests |
|---|---|
| `config/bundles.php` entry enabling `IDCTNatsMessengerBundle` without Flex | `transportFactoryIsRegisteredAndTaggedForMessengerDiscovery` (the bundle enabled in a kernel), `flexFindsTheBundleTheWayItGuessesBundleClasses`, `testReadmePhpExampleIsSyntacticallyValid` |
| Custom serializer extending `AbstractEnveloperSerializer` | `readmeCustomSerializerExample_EncodeDecode_RoundTrips`, `readmeCustomSerializerExample_DecodeInvalidBody_ThrowsException`, `testReadmePhpExampleIsSyntacticallyValid` |
| `$bus->dispatch(new OrderPlaced($orderId), [new DeduplicationIdStamp(...)])` | `testSendUsesAnApplicationDeduplicationIdWithTheOptionOff`, `testReadmePhpExampleIsSyntacticallyValid`, Behat scenario `A message dispatched again with the same deduplication id is stored once` |
| Igbinary serializer configuration example | `theReadmesIgbinaryExampleGivesTheTransportTheIgbinarySerializer`, `theReadmesSetupWithoutTheBundleGivesAWorkingTransport`, `aSignedMessageSurvivesTheSerializerUnderEveryIdTheBundleGivesIt`, `createTransport_UsesProvidedSerializer`, `serialize_WithValidEnvelope_ReturnsSerializedString`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `testConstructorWithoutIgbinaryDoesNotCrash`, Behat scenario `Failed message routed to Symfony failure transport via TERM with the igbinary serializer` |
| `$bus->dispatch(new MyMessage(), [new DelayStamp(30000)])` | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders` |
| `$transport->getMessageCount()` | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending` |
| Controller dispatching message (`MessageBus`) | `testReadmePhpExampleIsSyntacticallyValid`, `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, Behat scenario `Complete message flow - send, check stats, consume, verify` |
| Handler using the `#[AsMessageHandler]` attribute | `testReadmePhpExampleIsSyntacticallyValid`, Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `High-volume message processing with file output verification` |
| `symfony console messenger:consume nats_transport` | Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `Partial message consumption with multiple consumers` |
| `symfony console messenger:consume nats_transport --keepalive=10` | Behat scenario `A message handled for longer than ack_wait is not redelivered while keepalive runs` (with `--keepalive=1`), `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting` |
| `symfony console messenger:setup-transports nats_transport` | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenarios `Setup NATS stream with max age configuration`, `Setup command handles existing streams gracefully`, `Custom consumer name is registered in JetStream` |

### Service Registration Examples (YAML)

| README Example | Tests |
|---|---|
| `config/services.yaml` factory entry (only without the bundle) | `theReadmesSetupWithoutTheBundleGivesAWorkingTransport` (compiles the README's snippet) |
| `config/services.yaml` serializer entry (only without the bundle) | `theReadmesSetupWithoutTheBundleGivesAWorkingTransport` (compiles the README's snippet) |
| `config/services.yaml` factory entry with a logger (without the bundle, Logging section) | `createTransport_HandsTheFactorysLoggerToTheClient` (the constructor argument the snippet passes); the snippet itself is not compiled, its first line keeps it out of the "only without the bundle" set the test above loads |
| Upgrading from 5.4 or earlier: the earlier `services.yaml` entries next to the bundle, and `igbinary_serializer` | `theServiceEntriesUsedBeforeTheBundleKeepWorking`, `theReadmesIgbinaryExampleGivesTheTransportTheIgbinarySerializer` |

### Configuration Option Examples (YAML)

| README Option | Tests |
|---|---|
| `consumer: 'my-consumer'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildWithValidDsnReturnsConfiguration` |
| `batching: 1 / 5 / 10 / 20 / 50` | `testReadmeBatchingExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `max_batch_timeout: 0.5 / 1.0 / 2.0` | `testReadmeTimeoutExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `connection_timeout: 1.0 / 2.0 / 3.0` | `testReadmeTimeoutExamplesAreAccepted`, `testBuildWithConnectionTimeoutPropagatesMs` |
| `request_timeout: 10 / 30` | `testReadmeTimeoutExamplesAreAccepted`, `testBuildWithRequestTimeoutPropagatesMs`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_age: 0 / 86400` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_bytes: 1073741824` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_messages: 1000000` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_messages_per_subject: 1000` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_storage: 'file' / 'memory'` | `testReadmeStreamRetentionExamplesAreAccepted`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues` |
| `stream_replicas: 1 / 3` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeAuditTransportOptionsAreAccepted`; left unset, an existing stream keeps its count (High Availability): `testSetupPreservesExistingReplicaCountWhenStreamReplicasNotConfigured`, `testSetupOverridesExistingReplicaCountWhenStreamReplicasExplicitlyConfigured` |
| `stream_placement_cluster: 'east'`, `stream_placement_tags: ['ssd', 'eu-west']` / `'ssd,eu-west'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildAcceptsStreamPlacementOptions`, `testBuildSplitsCommaSeparatedStreamPlacementTags`, `testSetupPassesStreamPlacementOnCreate` |
| `reconnect: false / true`, `max_reconnect_attempts: 10 / 20` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildWithReconnectOptionsPropagatesToNatsOptions`, `testBuildKeepsTheClientReconnectAttemptDefaultWhenOnlyReconnectIsEnabled` |
| `?reconnect=true&max_reconnect_attempts=20` (Automatic Reconnect, in a DSN) | `testBuildWithReconnectFromDsnQueryString` |
| `resend_on_reconnect: false / true`, `?reconnect=true&deduplicate=true&resend_on_reconnect=true` (Automatic Reconnect) | `testReadmeConfigurationOptionsAreAccepted`, `testBuildWithResendOnReconnectPropagatesToNatsOptions` |
| `retry_handler: 'symfony' / 'nats'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildUsesRetryHandlerFromQuery`, functional NAK/TERM scenarios |
| `retry_via: null` (`copy` / `nak`) | `testRetryViaIsCopyUnlessSetToNak`, `testBuildWithInvalidRetryViaThrowsException`, `testBuildRefusesRetryViaInNatsMode`, the Behat feature `nats_retry_via.feature`, the example `retry-via-nak.php` |
| `deduplicate: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testDeduplicateIsOffByDefaultAndCanBeEnabled`, Behat scenario `Distinct messages are all stored with the deduplicate option` |
| `scheduled_messages: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testReadmeScheduledMessagesDsnEnablesFeature`, `testBuildWithScheduledMessagesEnabledSetsFlag` |
| TLS options (all) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, functional TLS/mTLS scenarios |
| Auth options (token, username, password) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, for the token also Behat scenario `Messages are sent and consumed by a client that logs in with a token` and the example `token-login.php` |
| NKey Authentication (`nkey_seed`) | `testBuildWithNkeySeedPassesASignerAndTheSeedsPublicKey`, `testBuildReadsTheNkeySeedFromTheDsnQuery`, the NKey scenarios above (real logins on plain, TLS and mTLS servers), `testCredentialsAreRefusedWhenBuiltOrReachTheServerInTheConnect`, the example `nkey-login.php` |
| JWT Users (`jwt` with `nkey_seed`) | `testBuildPassesAJwtWithItsSeedToTheClient`, `testBuildRejectsAJwtWithoutASeed`, `testBuildRejectsAJwtOptionThatIsNotAJwtWithoutRepeatingIt`, the JWT scenarios above (real logins on an operator-mode server), `testCredentialsAreRefusedWhenBuiltOrReachTheServerInTheConnect`, the example `jwt-login.php` |
| Multi-subject example (shared stream, per-transport options) | `testReadmeMultiSubjectOptionsAreAccepted` |
| `stream_max_age: 2592000` (audit, 30 days) | `testReadmeAuditTransportOptionsAreAccepted` |
| Cluster example (`stream_replicas: 3`, `reconnect: true`, `max_reconnect_attempts: 20`, `tls_required: true`, `tls_ca_file`) | `testReadmeClusterOptionsAreAccepted`, the examples `cluster-failover.php` and `stream-placement.php` |

### Consumer Strategy Examples

| README Example | Tests |
|---|---|
| Strategy A: same consumer, batching=1 | `testReadmeBatchingExamplesAreAccepted` (batching=1), functional shared-consumer scenarios |
| Strategy B: different consumers, any batching | `testReadmeMultiSubjectOptionsAreAccepted`, functional partial-consumption scenarios |

### Operational Examples Backed Indirectly

| README Example | Verification |
|---|---|
| `nats-server -js` | Environment prerequisite; functional scenarios begin after JetStream is available |
| `nats stream list/info`, `nats consumer list/info` | Manual NATS CLI inspection; underlying state is covered by Behat scenarios `Setup NATS stream with max age configuration`, `Custom consumer name is registered in JetStream`, and `Complete message flow - send, check stats, consume, verify` |
| Troubleshooting consume/setup command snippets | Manual diagnosis commands; related transport behavior is covered by the setup, consumer, and message-flow scenarios listed above |
| `composer require idct/symfony-nats-messenger` | Package installation step; the installed library is exercised by the unit and functional suites rather than by a separate installation test |
| Contributor verification command blocks (`composer test`, `composer test:unit`, `composer test:functional`) | Repository workflow commands used directly to validate changes rather than library runtime behavior |
| Reading a `.creds` file with `awk`, and the `nsc` commands of the JWT section | Run by hand on `nsc` 2.12.0 and NATS 2.15, on a real file, through to `messenger:setup-transports`. `tests/nats/auth/generate.sh` runs the same kind of `nsc` commands whenever `composer nats:start` finds no complete set, so on every CI run |
| Using a NATS Cluster: the failover table, the first dial knowing only the DSN's server, TLS on a cluster | Measured by hand on the test cluster (nats-server 2.15.1, client 2.27.2) and on a throwaway two-server TLS cluster (#86); `cluster-failover.php` covers the failover with `reconnect: true` in CI. TLS for learned servers, the certificate name and `tls_peer_name` are client behaviour |

### Not Testable by Transport (Symfony / NATS responsibility)

| README Example | Reason |
|---|---|
| `framework.messenger.transports` YAML structure | Symfony config parsing |
| Flex adding the bundle on install and update, and leaving it in `config/bundles.php` on a downgrade (Upgrading from 5.4 or earlier) | Composer and Flex behaviour, checked by hand with Flex 2.11 and Symfony 8.1; `flexFindsTheBundleTheWayItGuessesBundleClasses` guards the class name and file Flex relies on |
| Exact Symfony Console formatting for `messenger:consume` / `messenger:setup-transports` | Symfony CLI output is framework-owned even though the transport behavior is exercised functionally |
| Exact NATS CLI output for `nats-server`, `nats stream ...`, `nats consumer ...` | NATS server and CLI output is external to this package |
