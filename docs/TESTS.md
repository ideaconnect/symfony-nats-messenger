# Test Coverage Map

This document maps each feature of the Symfony NATS Messenger Bridge to the tests that cover it.

## Unit Tests

### Transport Core (`tests/unit/NatsTransportTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing & validation** | `testConstructorWithValidDsnInitializesTransport`, `testConstructorWithDottedTopicInitializesTransport`, `testConstructorWithInvalidDsnThrowsException`, `testConstructorWithoutPathThrowsException`, `testConstructorWithoutTopicThrowsException`, `testConstructorWithWildcardTopicThrowsException` |
| **Message sending** | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, `testSendSerializationFailureUsesErrorDetailsStampMessage`, `testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp` (and publishes nothing), `testSendPublishesLargePayloadWithoutTruncation`, `testSendSerializationFailureKeepsTheSerializerErrorAsThePreviousException` |
| **Publish error handling** | `testSendThrowsWhenJetStreamHeaderPublishReturnsError` (publish ack parsing/validation is delegated to the client's `JetStreamContext::publish()`, surfaced as a `JetStreamException`) |
| **Message receiving** | `testGetReturnsDecodedEnvelopeWithHeadersAndMessageId`, `testGetLeavesOutHeadersWhoseNameIsAnInteger` (a header named `1` or `-5` arrives as an int key and is not passed to `decode()`, which takes string names, so a serializer that reads the names as strings under strict types decodes the message), `testGetTermsEmptyPayloadMessagesToStopRedelivery`, `testGetSkipsMessagesWithoutReplySubject`, `testGetReturnsEmptyArrayWhenThePullReports404`, `testGetReturnsEmptyArrayWhenBatchRequestTimesOut`, `testGetRethrowsUnexpectedJetStreamExceptions`, `testGetDecodeFailureUsesTermWhenReplySubjectExists`, `testGetDecodeFailureUsesNakWhenRetryHandlerIsNats`, `testGetDecodeFailureKeepsOriginalErrorWhenRejectAlsoFails`, `testGetWithMultipleValidMessagesReturnsAll`, `testGetWithBatchingConfigPassesBatchSizeToFetchBatch`, `testGetDecodesLargePayloadWithoutTruncation`, `testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer`, `testGetDeliversTheRestOfTheBatchAfterAMessageWithoutAReplySubject` |
| **ACK / reject** | `testFindReceivedStampReturnsTransportStamp`, `testAckWithoutTransportStampThrowsException`, `testAckAcknowledgesReceivedEnvelope`, `testAckUsesAckSyncWhenEnabled`, `testRejectWithoutTransportStampThrowsException`, `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats` |
| **Keepalive (`messenger:consume --keepalive`)** | `testKeepaliveSendsInProgressForReplyToken`, `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting` (needs the pcntl and posix extensions), `testKeepaliveDoesNotWaitForTheAcknowledgement`, `testKeepaliveWithoutAConnectionSendsNothingAndDoesNotDial`, `testKeepaliveWhoseAcknowledgementFailsRaisesNoUnhandledError`, `testKeepaliveWithoutTransportStampThrowsException`, `testKeepaliveNeitherPingsNorDials` |
| **Retry handler (TERM / NAK)** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseTermTransportPath`, `testHandleFailedDeliveryUsesBaseNakTransportPath`, `testConstructorWithInvalidRetryHandlerThrowsException` |
| **NATS retry handler ignores Symfony's retry strategy** | `testNatsModeDoesNotPublishTheCopySymfonysRetrySends`, `testSymfonyModePublishesTheCopySymfonysRetrySends`, `testNatsModeStillPublishesWhatIsNotSymfonysRetryCopy` (failure-transport copy, message not received here, plain dispatch), `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy` |
| **A delivery sent for retry in nats mode is NAKed, whether the worker acknowledges it (Messenger 7.4.22, 8.1.10 and later) or rejects it (#90)** | `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy` and `testWorkerInNatsModeNaksOnlyTheDeliveryItSentForRetry` (through Symfony's Worker; they fail on 7.4.22 / 8.1.10 without the fix), `testAckNaksADeliveryWhoseRetryCopyNatsModeLeftOut` (defaults, `nak_delay`, `ack_sync`), `testADeliverySentForRetryIsNakedOnce` (settled by `ack()` or `reject()`), `testAckNaksOnlyTheDeliveryTheRetryCopyBelongsTo` (another message, another delivery of the same message), `testEveryDeliverySentForRetryIsNakedWhenSettledLater`, `testSymfonyModeAcksADeliveryItPublishedTheRetryCopyOf` |
| **A transport in nats mode warns once that Symfony's retry strategy is on (#94)** | `testNatsModeWarnsOnceThatSymfonysRetryStrategyIsOn` (through Symfony's Worker with FrameworkBundle's default strategy: two left-out copies, one warning with the exact text), `testEachTransportWarnsOnceAboutItsOwnStreamAndSubject` (once per transport object, copies with and without a delivery token), `testNatsModeWithoutSymfonysRetriesLogsNoWarning` (Worker with `max_retries: 0`), `testNoWarningWhenSendLeavesOutNoRetryCopy` (symfony mode; nats mode: failure-transport copy, message not received in this process, plain dispatch) |
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
| **Consumer validation** | `testSetupRejectsUnexpectedConsumerConfiguration`, `testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig`, `testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy`, `testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject`, `testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName` |
| **Message count** | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testMessageCountThatCannotConnectDialsOnceAndReturnsZero`, `testGetMessageCountReadsAStreamStateWithoutAMessageCountAsZero` |
| **Connection check (`ping_after_idle`: after idling, after a failure, and before the NAK or TERM of a failed delivery)** | `testOperationThatFailedOnTheConnectionMakesTheNextOneCheckItFirst`, `testAnsweredPingAfterAFailureKeepsTheConnectionAndEndsTheCheck`, `testJetStreamReplyDoesNotMakeTheNextOperationCheckTheConnection`, `testPullTheServerDidNotAnswerMakesTheNextOperationCheckTheConnection`, `testFailureMakesNoOperationCheckTheConnectionWhenTheCheckIsOff`, `testCloseEndsTheCheckAFailureCalledFor`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testIdleConnectionIsCheckedWithAPingAndKeptWhenTheServerAnswers`, `testIdleConnectionThatDoesNotAnswerThePingIsReplacedBeforeTheOperation`, `testFailedCloseOfTheIdleConnectionDoesNotStopItsReplacement`, `testPingUnansweredWithinTheConnectionTimeoutReplacesTheConnection`, `testRecentlyUsedConnectionIsNotPinged`, `testPingAfterIdleZeroTurnsTheCheckOff`, `testIdleTimeCountsFromTheEndOfAPull`, `testIdleTimeCountsFromTheEndOfAnEmptyPull`, `testAutoSetupVerifiesTheConnectionThatReplacedAnIdleOne`, `testAnsweredPingCountsAsUseSoOneOperationPingsOnce`, `testKeepaliveNeitherPingsNorDials`, `testConnectionUnusedForExactlyPingAfterIdleIsNotPinged`, `testPullRefusedAtTheSubscriptionLimitFailsWithATransportExceptionAndTheNextOperationChecksTheConnection` (a pull refused at the subscription limit still calls for the check, since the server may close the connection behind the error, #76), `testSettlingAFailedDeliveryChecksTheConnectionFirst` (`reject()` in symfony mode (TERM) and nats mode (NAK, with and without `nak_delay`), and the NAK `ack()` sends for a delivery whose retry copy was left out: one PING, then the acknowledgement, on a connection used a moment ago; the next ACK pings nothing), `testPingAfterIdleZeroTurnsTheCheckBeforeSettlingAFailedDeliveryOff` |
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
times out and leaves the client Connecting depends on how long the dial is held against `request_timeout` (0.1 s here,
`connection_timeout` 0.02 s, the client's reconnect delay 1 ms). The transport's PINGs are counted through `rtt()`; the
client's heartbeat is off (#81).

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
| **TLS configuration** | `testBuildWithTlsSchemeUsesTlsServerProtocol`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, `testBuildRecognizesTheTlsSchemeInAnyCase`, `testReadmeClusterOptionsAreAccepted` (the `+tls` scheme with `tls_required`, as a cluster needs) |
| **TLS peer verification stays on unless explicitly disabled** | `testTlsVerifyPeerStaysOnUnlessExplicitlyDisabled` (data provider: false/0/no/off disable it; recognized truthy, unrecognized, empty, null and non-scalar values keep it on), `testTlsVerifyPeerInTheDsnStaysOnUnlessExplicitlyDisabled` (DSN query: left out, empty, unrecognized, false, off) |
| **Authentication** | `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent`, `testBuildNormalizesStringBooleanAndNullableStringOptions`, `testBuildNormalizesIntegerBooleanOptions`, `testBuildPassesScalarCredentialOptionsAsStrings` (int, float, true), `testBuildFallsBackToTheDsnCredentialsForNonScalarOptions` |
| **NKey authentication (`nkey_seed`)** | `testBuildWithNkeySeedPassesASignerAndTheSeedsPublicKey` (a signer and the derived public key reach the client, no user or password), `testBuildReadsTheNkeySeedFromTheDsnQuery`, `testBuildAcceptsAnNkeyThatIsTheSeedsPublicKey`, `testBuildRejectsAnNkeyThatIsNotTheSeedsPublicKey` (the error names the seed's key and never repeats the value, which may be a seed), `testBuildRejectsAnNkeyWithoutASeed` (the same), `testBuildRejectsASeedNextToAUsernamePasswordOrToken` (from the DSN or the options; the error repeats no value), `testBuildRejectsAJwtWithoutASeed`, `testBuildPassesAJwtWithItsSeedToTheClient` (the JWT, the seed's public key and the signer reach the client; no operator-mode server in the suite), `testBuildRejectsAnInvalidNkeySeedWithoutRepeatingIt` (the message never contains the seed), `testTheNkeyOptionIsNotDeprecated` (the seed alone, and `nkey` repeating its key); without a seed no signer is passed: `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions` |
| **Option coercion edge cases** | `testBuildWithNonScalarOptionsCoerceToSafeDefaults`, `testBuildCoercesNonZeroIntegerBooleanOptionToTrue`, `testBuildCoercesUppercaseBooleanStringToTrue`, `testBuildWithPathMissingTopicThrowsException` |
| **Scheduled messages** | `testBuildWithScheduledMessagesEnabledSetsFlag`, `testBuildWithScheduledMessagesDisabledByDefault`, `testBuildWithScheduledMessagesFromDsnQueryString` |
| **Acknowledgement (ack_sync)** | `testBuildWithAckSyncEnabledSetsFlag`, `testBuildWithAckSyncDisabledByDefault`, `testBuildWithAckSyncFromDsnQueryString` |
| **Deduplication (`deduplicate`)** | `testDeduplicateIsOffByDefaultAndCanBeEnabled` (default, options, DSN query, options over query), `testReadmeConfigurationOptionsAreAccepted` |
| **Extended stream/consumer options + auto_setup** | `testBuildAcceptsAndNormalizesNewStreamAndConsumerOptions`, `testBuildLeavesNewOptionsUnsetByDefault`, `testBuildParsesNewOptionsFromDsnQuery`, `testBuildWithInvalidRetentionThrowsException`, `testBuildWithInvalidDiscardThrowsException`, `testBuildWithInvalidCompressionThrowsException`, `testBuildWithInvalidReplayPolicyThrowsException`, `testBuildWithDuplicateWindowExceedingMaxAgeThrowsException`, `testBuildAllowsDuplicateWindowWhenMaxAgeIsUnlimited`, `testBuildAllowsDuplicateWindowEqualToMaxAge`, `testBuildWithInvalidMaxAckPendingThrowsException`, `testBuildWithInvalidStreamMaxConsumersThrowsException`, `testBuildWithZeroStreamMaxMessageSizeThrowsException`, `testBuildWithStreamMaxMessageSizeExceedingInt32ThrowsException`, `testBuildAcceptsStreamMaxMessageSizeAtTheInt32Boundary`, `testBuildWithZeroDuplicateWindowThrowsException`, `testBuildWithOverlongStreamDescriptionThrowsException`, `testBuildAcceptsStreamDescriptionAtTheLengthLimit`, `testBuildWithUnrecognizedTriStateBooleanThrowsException`, `testBuildAcceptsEveryRecognizedBooleanTokenForTriStateFlags`, `testBuildTreatsANullMaxAgeAsUnlimitedForTheDuplicateWindow`, `testBuildWithInvalidInactiveThresholdThrowsException` (zero, negative, non-numeric), `testBuildRejectsAnUnrecognizedValueInAnyTriStateFlag` (each later flag, after a boolean `stream_deny_delete`) |
| **NATS-native retry tuning** | `testBuildRetryTuningDefaults`, `testBuildAcceptsNatsRetryTuningOptions`, `testBuildWithNegativeNakDelayThrowsException`, `testBuildWithNonPositiveAckWaitThrowsException`, `testBuildWithNonIntegerMaxDeliverThrowsException`, `testBuildWithNonListBackoffThrowsException`, `testBuildWithNonNumericBackoffElementThrowsException`, `testBuildWithMaxDeliverNotExceedingBackoffThrowsException`, `testBuildWithBackoffFromDsnQueryString`, `testBuildAcceptsZeroAndFractionalBackoffEntries`, `testBuildAcceptsMaxDeliverWithoutBackoff` |
| **Stream placement** | `testBuildAcceptsStreamPlacementOptions`, `testBuildSplitsCommaSeparatedStreamPlacementTags`, `testBuildParsesStreamPlacementFromDsnQueryString`, `testBuildLeavesStreamPlacementUnsetByDefault`, `testBuildWithInvalidStreamPlacementClusterThrowsException`, `testBuildWithInvalidStreamPlacementTagsThrowsException` |
| **Reconnect** | `testBuildTurnsClientReconnectAndPedanticModeOff`, `testBuildWithReconnectOptionsPropagatesToNatsOptions`, `testBuildWithReconnectFromDsnQueryString`, `testBuildKeepsTheClientReconnectAttemptDefaultWhenOnlyReconnectIsEnabled`, `testBuildWithInvalidMaxReconnectAttemptsThrowsException`, `testBuildPassesTheLoggerToTheClientAndNoneByDefault` |
| **Sending again on the recovered connection (`resend_on_reconnect`)** | `testResendOnReconnectIsOffByDefault`, `testBuildWithResendOnReconnectPropagatesToNatsOptions` (options, DSN, options over DSN), `testBuildWithResendOnReconnectWithoutReconnectThrowsException`, `testBuildWithResendOnReconnectAndADuplicateWindowNotLongerThanTheRequestTimeoutThrowsException` (equal, and shorter), `testBuildWithResendOnReconnectAcceptsALongerOrAnUnsetDuplicateWindow` |
| **Option completeness** | `testDefaultOptionsCoversAllTransportOptionCases` |

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
| **TERM failure** | Failed message routed to Symfony failure transport via TERM |
| **TERM failure with the igbinary serializer (retry and failure-transport re-sends)** | Failed message routed to Symfony failure transport via TERM with the igbinary serializer |

### Delayed Messages (`tests/functional/features/nats_delayed.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Scheduled delivery** | Delayed messages are delivered after the scheduled time |
| **Not delivered early** | Delayed messages are not available to the consumer before the scheduled time |
| **Larger delayed batch** | A larger batch of delayed messages all arrive after the scheduled time (10 messages) |
| **Delayed load balancing** | Delayed messages are load-balanced across multiple consumers (12 messages / 3 consumers) |

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

| Feature | Scenarios |
|---------|-----------|
| **NKey seed, no user or password** | Messages are sent and consumed by a user that authenticates with an NKey seed (the NKey user in `tests/nats/nats.conf`) |

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
supported NATS. Each prints `OK ...` when what it shows held, and fails otherwise.

| Example | Verifies |
|---|---|
| `send-and-consume.php` | 3 messages sent are waiting, then received in order and acknowledged, leaving the queue empty |
| `duplicate-protection.php` | an application id sent twice is stored once; the envelope `send()` returned, sent again, is dropped; its retry (`RedeliveryStamp` 1) is stored |
| `nats-retry.php` | with `retry_handler: nats`, `nak_delay: 1` and `max_deliver: 3`, Symfony's Worker and retry listener with FrameworkBundle's default strategy (3 retries) over a message whose handler fails once: the handler runs exactly twice, at least 0.9 s apart, the stream holds the one message (the retry was not published) and the consumer has nothing pending; fails on 5.7.1 with Symfony Messenger 7.4.22 / 8.1.10 or later, where the message was acknowledged after its first failure (#90) |
| `request-timeout.php` | against a `no_ack` stream, a send with `request_timeout: 1` gives up after about 1 s although the message is stored, and sending it again with the same deduplication id stores nothing more |
| `keepalive.php` | with `keepalive()` called from a SIGALRM handler every second, a message handled for 5 s on `ack_wait: 2` is not redelivered to a second worker (on 5.2.1 the first alarm broke the worker) |
| `connection-checks.php` | sends after idling past `ping_after_idle` and after `close()` both succeed |
| `reconnect.php` | with `reconnect: true`, `auto_setup` and a memory stream, `docker restart nats_test` during a pull: that pull and every pull until the restart is over end empty without an exception (`409 Server Shutdown`, then the client's recovery), the message sent once the server is back is stored in the recreated stream and received, and the logger the transport was given received the client's `Reconnected` |
| `cluster-failover.php` | on the three-server test cluster, an R3 stream; a worker with `reconnect: true` whose DSN names only the stream's leader receives 2 of 4 messages, `docker stop` stops that server, and the worker receives the other 2 and one it sends afterwards without an exception, through a server it learned from the cluster; another server leads the stream; the logger received `Reconnected` |
| `handler-failing-during-failover.php` | on the three-server test cluster, once per retry mode: an R3 stream with `nak_delay: 1` and `ack_wait: 30`; Symfony's Worker and retry listener with FrameworkBundle's default strategy, the worker's DSN naming only the stream's leader with `reconnect: true`; the handler stops that server with `docker stop` and fails on its first attempt: it runs again on the same worker within 10 s of the failure (in nats mode not before 0.9 s), nothing is pending or undelivered and the logger received `Reconnected`. Fails on 5.7.1 in nats mode, where the NAK went into the connection the server had closed and the message came back only after `ack_wait` (1 attempt within the worker's 15 s), and with client 2.27.2 in symfony mode, where the retry copy went into that connection and its publish ended the worker with `TimeoutException` |
| `handler-succeeding-during-failover.php` | the same setup without the retry listener, once per retry mode; the handler stops the stream leader with `docker stop` and succeeds; the worker pulls on for 3 s: the message was handled once, nothing is pending or undelivered (the ACK reached the cluster) and the logger received `Reconnected`. Fails with client 2.27.2, where the ACK went into the closed connection and stayed pending until `ack_wait` |
| `stream-placement.php` | on the three-server test cluster (two servers tagged `disk:ssd`, one `disk:hdd`): `stream_replicas: 2` with `stream_placement_tags: disk:ssd` puts the replicas on the two ssd servers; `stream_placement_cluster` set alone stores `{cluster, tags}` with the live tags and the replicas stay; one replica on `disk:hdd` in one update fails `setup()` with "can not move and scale" and the staging advice, leaving the stream as it was; staged (replicas first, then the tags) the stream moves to the hdd server with its 3 messages. The staged step from 2 replicas to 1 is where NATS moves the consumer, and on NATS 2.10 a consumer request of that `setup()` could meet the moment without a consumer leader (err_code 10008), which failed the example in CI before `setup()` asked again |

The cluster examples run against the cluster `composer nats:start` starts next to the single servers
(`tests/nats/nats-cluster*.conf`, ports 4226 to 4228, see `examples/README.md`), in both CI jobs.

### Automatic Reconnect (`tests/functional/features/nats_reconnect.feature`)

The main test server is restarted with `docker restart` while a process holds a connection to it, with `reconnect: true`
(#81). A graceful stop ends every pull in flight with `409 Server Shutdown` and closes every connection; a memory stream
does not survive it.

| Feature | Scenarios |
|---------|-----------|
| **A worker polls through a server restart without exiting** | A worker keeps consuming across a server restart (the consumer is started before the restart and consumes the five messages sent after it) |
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
on the PHP 8.5 job and daily (`.github/workflows/mutation.yml`). The suite scores 98.6% MSI and covered
MSI: 1043 of 1058 mutants killed, none timed out, with 100% mutation code coverage.

Scores reported before, 100% among them, were not real. Infection runs each mutant in a PHPUnit process
whose bootstrap puts its include interceptor on `file://`; the interceptor dropped the wrapper BypassFinals
puts on top of it, so the client's final classes could not be doubled there, every test that doubles one
failed, and Infection counted the mutant as killed. `tests/bootstrap.php` now takes the interceptor's file
swap over (`MutationTestingBootstrapTest`). In a mutant process the mutated class is therefore compiled from
`.infection/infection/mutant.*.infection.php`, with the original line numbers, so a breakpoint set in `src/`
is not hit there.

Two checks around the run keep the score honest; CI runs both:

- **Before it, `composer test:mutation:canary`** runs Infection with `--noop` and the `TrueValue` and
  `FalseValue` mutators. Its mutants (63 today) change nothing, so every one must escape;
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

Of the 15 mutants that survive:

- **13 are equivalent**: no input makes them behave differently.
- **2 make no difference with the real client**: its acknowledgement calls do not read the `sid` of the
  message `buildAckMessage()` gives them. A test could kill them only by asserting on the `sid` its client
  double receives, which would pin a detail rather than behaviour.

| Mutant | Why it survives |
|---|---|
| `connectionUsable()`: `$ping->ignore()` removed | `await()` subscribes to the PING, which marks it handled even when the wait gives up |
| `buildAckMessage()`: `sid` 1 or -1 instead of 0 (2 mutants) | The client's ack, ackSync, nak, nakWithDelay, term and inProgress read only the reply subject, so only an assertion on the `sid` itself would see the change |
| `buildUpdatedStreamConfiguration()`: a missing `duplicate_window` read as -1 or 1 instead of 0 (2 mutants) | A positive max age is at least 10^9 nanoseconds, so none of these exceeds it |
| `buildUpdatedStreamConfiguration()`: the window clamped when it equals the max age, too | Clamping it then writes the same value |
| `NatsTransportConfiguration`: the defaults of `batching` (0), `stream_max_age` (-1) and `stream_replicas` (0) (3 mutants) | `max()` clamps each to what the original default gives |
| `assertPositiveNumber()`, `assertNonNegativeNumber()`: `ceil()` or `round()` instead of `floor()` (4 mutants) | All three return the number itself exactly when it is whole |
| `assertDuplicateWindowNotExceedingMaxAge()`: a null max age read as -1 instead of 0 | Both mean no age limit there |
| `requiredString()`: `&&` instead of `\|\|` | `parseDsn()` already requires a host, and `parse_url()` never gives an empty one |

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
mutant fails a test within seconds and counts as killed. The 60-second `timeout` in `infection.json5` stays, for
any other loop that never ends.

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
| `deduplicate: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testDeduplicateIsOffByDefaultAndCanBeEnabled`, Behat scenario `Distinct messages are all stored with the deduplicate option` |
| `scheduled_messages: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testReadmeScheduledMessagesDsnEnablesFeature`, `testBuildWithScheduledMessagesEnabledSetsFlag` |
| TLS options (all) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, functional TLS/mTLS scenarios |
| Auth options (token, username, password) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions` |
| NKey Authentication (`nkey_seed`) | `testBuildWithNkeySeedPassesASignerAndTheSeedsPublicKey`, `testBuildReadsTheNkeySeedFromTheDsnQuery`, Behat scenario `Messages are sent and consumed by a user that authenticates with an NKey seed` |
| JWT Users (`jwt` with `nkey_seed`) | `testBuildPassesAJwtWithItsSeedToTheClient`, `testBuildRejectsAJwtWithoutASeed` |
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
| Using a NATS Cluster: the failover table, the first dial knowing only the DSN's server, TLS on a cluster | Measured by hand on the test cluster (nats-server 2.15.1, client 2.27.2) and on a throwaway two-server TLS cluster (#86); `cluster-failover.php` covers the failover with `reconnect: true` in CI. TLS for learned servers, the certificate name and `tls_peer_name` are client behaviour |

### Not Testable by Transport (Symfony / NATS responsibility)

| README Example | Reason |
|---|---|
| `framework.messenger.transports` YAML structure | Symfony config parsing |
| Flex adding the bundle on install and update, and leaving it in `config/bundles.php` on a downgrade (Upgrading from 5.4 or earlier) | Composer and Flex behaviour, checked by hand with Flex 2.11 and Symfony 8.1; `flexFindsTheBundleTheWayItGuessesBundleClasses` guards the class name and file Flex relies on |
| Exact Symfony Console formatting for `messenger:consume` / `messenger:setup-transports` | Symfony CLI output is framework-owned even though the transport behavior is exercised functionally |
| Exact NATS CLI output for `nats-server`, `nats stream ...`, `nats consumer ...` | NATS server and CLI output is external to this package |
