# Test Coverage Map

This document maps each feature of the Symfony NATS Messenger Bridge to the tests that cover it.

## Unit Tests

### Transport Core (`tests/unit/NatsTransportTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing & validation** | `testConstructorWithValidDsnInitializesTransport`, `testConstructorWithDottedTopicInitializesTransport`, `testConstructorWithInvalidDsnThrowsException`, `testConstructorWithoutPathThrowsException`, `testConstructorWithoutTopicThrowsException`, `testConstructorWithWildcardTopicThrowsException` |
| **Message sending** | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, `testSendSerializationFailureUsesErrorDetailsStampMessage`, `testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp`, `testSendPublishesLargePayloadWithoutTruncation`, `testSendSerializationFailureKeepsTheSerializerErrorAsThePreviousException` |
| **Publish error handling** | `testSendThrowsWhenJetStreamHeaderPublishReturnsError` (publish ack parsing/validation is delegated to the client's `JetStreamContext::publish()`, surfaced as a `JetStreamException`) |
| **Message receiving** | `testGetReturnsDecodedEnvelopeWithHeadersAndMessageId`, `testGetLeavesOutHeadersWhoseNameIsAnInteger` (a header named `1` or `-5` arrives as an int key and is not passed to `decode()`, which takes string names, so a serializer that reads the names as strings under strict types decodes the message), `testGetTermsEmptyPayloadMessagesToStopRedelivery`, `testGetSkipsMessagesWithoutReplySubject`, `testGetReturnsEmptyArrayWhenThePullReports404`, `testGetReturnsEmptyArrayWhenBatchRequestTimesOut`, `testGetRethrowsUnexpectedJetStreamExceptions`, `testGetDecodeFailureUsesTermWhenReplySubjectExists`, `testGetDecodeFailureUsesNakWhenRetryHandlerIsNats`, `testGetDecodeFailureKeepsOriginalErrorWhenRejectAlsoFails`, `testGetWithMultipleValidMessagesReturnsAll`, `testGetWithBatchingConfigPassesBatchSizeToFetchBatch`, `testGetDecodesLargePayloadWithoutTruncation`, `testGetUsesConfiguredConsumerNameSoWorkersShareOneDurableConsumer`, `testGetDeliversTheRestOfTheBatchAfterAMessageWithoutAReplySubject` |
| **ACK / reject** | `testFindReceivedStampReturnsTransportStamp`, `testAckWithoutTransportStampThrowsException`, `testAckAcknowledgesReceivedEnvelope`, `testAckUsesAckSyncWhenEnabled`, `testRejectWithoutTransportStampThrowsException`, `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats` |
| **Keepalive (`messenger:consume --keepalive`)** | `testKeepaliveSendsInProgressForReplyToken`, `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting` (needs the pcntl and posix extensions), `testKeepaliveDoesNotWaitForTheAcknowledgement`, `testKeepaliveWithoutAConnectionSendsNothingAndDoesNotDial`, `testKeepaliveWhoseAcknowledgementFailsRaisesNoUnhandledError`, `testKeepaliveWithoutTransportStampThrowsException`, `testKeepaliveNeitherPingsNorDials` |
| **Retry handler (TERM / NAK)** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseTermTransportPath`, `testHandleFailedDeliveryUsesBaseNakTransportPath`, `testConstructorWithInvalidRetryHandlerThrowsException` |
| **NATS retry handler ignores Symfony's retry strategy** | `testNatsModeDoesNotPublishTheCopySymfonysRetrySends`, `testSymfonyModePublishesTheCopySymfonysRetrySends`, `testNatsModeStillPublishesWhatIsNotSymfonysRetryCopy` (failure-transport copy, message not received here, plain dispatch), `testWorkerRetryInNatsModeNaksTheOriginalWithoutPublishingACopy` |
| **NATS-native retry tuning** | `testHandleFailedDeliveryUsesNakWithDelayWhenConfigured`, `testSetupAppliesConsumerRetryTuning` |
| **Stream setup (create)** | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupPassesNewStreamPolicyOptions`, `testSetupPassesEachTriStateStreamFlagIndependently`, `testSetupPassesNewConsumerOptions`, `testSetupCreatesNewStreamWithMaxMessages`, `testSetupCreatesNewStreamWithMaxMessagesPerSubject` |
| **Consumer replay policy (immutable)** | `testSetupPassesNewConsumerOptions`, `testSetupPreservesTheExistingConsumerReplayPolicyOverTheConfiguredOne`, `testSetupWritesReplayPolicyWhenExistingConsumerAlreadyMatches`, `testSetupOmitsReplayPolicyWhenTheExistingConsumerReportsNone`, `testSetupRethrowsNon404JetStreamExceptionFromConsumerLookup`, `testSetupPreservesAnOriginalReplayPolicyWhenTheOptionIsRemoved` |
| **Auto-setup provisioning** | `testAutoSetupProvisionsOnFirstSendOnce`, `testAutoSetupProvisionsOnFirstGet`, `testAutoSetupDisabledByDefaultDoesNotProvisionOnSend`, `testAutoSetupProvisionsBeforeTheFirstPublish`, `testAutoSetupProvisionsBeforeTheFirstFetch`, `testAutoSetupRetriesProvisioningAfterAFailedAttempt` |
| **Auto-setup re-provisioning (missing stream or consumer)** | `testAutoSetupReprovisionsWhenTheConsumerDisappeared`, `testGetTreats404AsEmptyWithoutReprovisioningWhenAutoSetupIsDisabled`, `testAutoSetupReprovisioningRetryStopsAfterOneAttempt`, `testAutoSetupRethrowsAnUnexpectedErrorFromTheRetriedPull`, `testAutoSetupReprovisionsForEveryMissingResourceStatus`, `testGetStillPropagates503WhenAutoSetupIsDisabled`, `testCloseResetsAutoSetupSoTheNextOperationProvisionsAgain`, `testAutoSetupVerifiesTheNewConnectionBeforeTheSameCallPublishes`, `testAutoSetupDoesNotReprovisionForAnUnexpectedPullError`, `testAutoSetupReadsA408FromTheRetriedPullAsEmpty` |
| **Stream setup (update existing)** | `testSetupUpdatesStreamWhenItAlreadyExists`, `testSetupUpdatesStreamWhenAlreadyInUseMessage`, `testSetupUpdatesStreamWhenAlreadyExistsInMessage`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, `testSetupUpdatesExistingStreamWithoutDuplicatingSubjects`, `testSetupUpdatesExistingStreamWithMaxMessages`, `testSetupUpdatesExistingStreamWithMaxMessagesPerSubject`, `testSetupUpdateResetsUnsetStreamLimitsToUnlimited`, `testSetupUpdateAppliesExplicitlyConfiguredStreamMaxConsumers`, `testSetupUpdateAppliesExplicitlyConfiguredStreamMaxMessageSize`, `testSetupUpdatePreservesServerRetentionOverAConfiguredOne`, `testSetupUpdateNeverCancelsAnExistingDenyFlag`, `testSetupUpdateClampsInheritedDuplicateWindowToTheConfiguredMaxAge`, `testSetupUpdateKeepsDuplicateWindowWhenMaxAgeIsUnlimited`, `testSetupUpdateConvertsConfiguredMaxAgeToNanoseconds`, `testSetupUpdateTreatsNonArrayServerSubjectsAsEmpty`, `testSetupPreservesExistingReplicaCountWhenStreamReplicasNotConfigured`, `testSetupOverridesExistingReplicaCountWhenStreamReplicasExplicitlyConfigured`, `testSetupUpdatesExistingStreamWithMaxBytes`, `testSetupUpdateLeavesOutServerSubjectsThatAreNotNonEmptyStrings` (scheduled messages off and on) |
| **Stream setup (error handling)** | `testSetupDoesNotTreatGenericBadRequestAsExistingStream`, `testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest`, `testSetupWrapsUnexpectedStreamCreationErrors`, `testSetupRethrowsNon404JetStreamExceptionFromStreamExistsCheck`, `testSetupWrapsConsumerCreationError`, `testSetupUpdateStreamFailureWrapsException`, `testSetupFailureKeepsTheCauseAsThePreviousException` |
| **Unsupported server feature** | `testSetupGivesClearErrorWhenScheduledMessagesUnsupported`, `testSetupWrapsUnsupportedFeatureGenericallyWhenNotScheduledMessages`, `testSetupNamesTheRejectedFeatureWhenItIsNotTheOneScheduledMessagesNeed` |
| **Consumer validation** | `testSetupRejectsUnexpectedConsumerConfiguration`, `testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig`, `testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy`, `testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject`, `testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName` |
| **Message count** | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testMessageCountThatCannotConnectDialsOnceAndReturnsZero`, `testGetMessageCountReadsAStreamStateWithoutAMessageCountAsZero` |
| **Connection check (`ping_after_idle`: after idling, or after a failure)** | `testOperationThatFailedOnTheConnectionMakesTheNextOneCheckItFirst`, `testAnsweredPingAfterAFailureKeepsTheConnectionAndEndsTheCheck`, `testJetStreamReplyDoesNotMakeTheNextOperationCheckTheConnection`, `testPullTheServerDidNotAnswerMakesTheNextOperationCheckTheConnection`, `testFailureMakesNoOperationCheckTheConnectionWhenTheCheckIsOff`, `testCloseEndsTheCheckAFailureCalledFor`, `testMessageCountFallbackRunsOnANewConnectionAfterTheLookupFailedOnTheOldOne`, `testIdleConnectionIsCheckedWithAPingAndKeptWhenTheServerAnswers`, `testIdleConnectionThatDoesNotAnswerThePingIsReplacedBeforeTheOperation`, `testFailedCloseOfTheIdleConnectionDoesNotStopItsReplacement`, `testPingUnansweredWithinTheConnectionTimeoutReplacesTheConnection`, `testRecentlyUsedConnectionIsNotPinged`, `testPingAfterIdleZeroTurnsTheCheckOff`, `testIdleTimeCountsFromTheEndOfAPull`, `testIdleTimeCountsFromTheEndOfAnEmptyPull`, `testAutoSetupVerifiesTheConnectionThatReplacedAnIdleOne`, `testAnsweredPingCountsAsUseSoOneOperationPingsOnce`, `testKeepaliveNeitherPingsNorDials`, `testConnectionUnusedForExactlyPingAfterIdleIsNotPinged` |
| **Connection (lazy init, dialling again after the client closed)** | `testConnectInitializesJetStreamContextFromClient`, `testJetStreamThrowsWhenConnectLeavesContextUnavailable`, `testConnectIsIdempotentAcrossOperations`, `testOperationAfterTheClientClosedDialsAgain` (send, get, ack, reject, getMessageCount), `testFailedDialAfterTheClientClosedSurfacesAsTheConnectionErrorAndIsRetried` |
| **Scheduled / delayed messages** | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`, `testSendDelayedMessageSchedulesAtRequestedDelay`, `testSendDelayedMessageNeverSchedulesBeforeRequestedDelay` (the delay is picked so that the requested time falls in the middle of a second, so the result does not depend on the clock), `testSendDelayedMessageWithLargeDelaySchedulesFarInTheFuture`, `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally`, `testSendWithZeroDelayPublishesNormally`, `testSendWithNegativeDelayPublishesNormally`, `testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders`, `testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag`, `testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject`, `testSetupUpdateRemovesOrphanedDelayedSubjectWhenScheduledMessagesDisabled`, `testSendWithoutDelayStampPublishesNormallyWhenScheduledMessagesAreEnabled` |
| **Duplicate protection (`deduplicate`, `DeduplicationIdStamp`)** | `testSendWithDeduplicationStampsTheEnvelopeAndPublishesItsMessageId`, `testSendKeepsTheDeduplicationIdTheEnvelopeCarries`, `testSendGivesEachRetryAndTheFailureTransportCopyAMessageIdOfItsOwn`, `testSendWithoutDeduplicationSendsNoMessageIdAndAddsNoStamp`, `testSendUsesAnApplicationDeduplicationIdWithTheOptionOff`, `testSendDelayedMessageWithDeduplicationPublishesItsMessageId`, `testSendGivesTheCopiesForTwoTransportsOnOneStreamMessageIdsOfTheirOwn`, `testSendGivesTheFailureCopiesOfTwoTransportsMessageIdsOfTheirOwn` |
| **Igbinary fallback** | `testConstructorWithoutIgbinaryDoesNotCrash` |
| **TLS DSN** | `testConstructorWithTlsDsnInitializesTransport` |

### Unanswered Pull (`tests/unit/UnansweredPullTest.php`)

Runs the real client against an in-memory server that answers the handshake and PINGs, and a pull only when told to, so a new client version that words its own pull deadline differently fails here.

| Feature | Tests |
|---------|-------|
| **A pull the server did not answer makes the next operation check the connection; one the server ended with 408 does not** | `testOnlyAPullTheServerDidNotAnswerMakesTheNextOneCheckTheConnection` |

### Transport Factory (`tests/unit/NatsTransportFactoryTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN scheme support** | `supports_WithNatsJetStreamScheme_ReturnsTrue`, `supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue`, `supports_WithNatsJetStreamTlsScheme_ReturnsTrue`, `supports_WithDifferentScheme_ReturnsFalse`, `supports_WithNatsButNotJetStream_ReturnsFalse`, `supports_WithAmqpScheme_ReturnsFalse`, `supports_WithEmptyString_ReturnsFalse`, `supports_WithHttpScheme_ReturnsFalse` |
| **Transport creation** | `createTransport_WithValidDsn_ReturnsNatsTransportInstance`, `createTransport_WithOptions_PassesOptionsToTransport`, `createTransport_UsesProvidedSerializer`, `createTransport_WithDefaultPort_ParsesDsnCorrectly`, `createTransport_WithoutAuth_ParsesDsnCorrectly`, `createTransport_WithQueryParams_ParsesConfigCorrectly` |

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
| **Client reconnect and pedantic mode stay off** | `testBuildTurnsClientReconnectAndPedanticModeOff` |
| **Idle connection check (`ping_after_idle`)** | `testPingAfterIdleDefaultsToThirtySeconds`, `testPingAfterIdleAcceptsZeroAndFractionsFromTheQueryAndTheOptions`, `testBuildWithInvalidPingAfterIdleThrowsException` (negative, non-numeric), `testReadmeConfigurationOptionsAreAccepted` |
| **Retry handler** | `testBuildUsesRetryHandlerFromQuery`, `testBuildWithInvalidRetryHandlerThrowsException` |
| **TLS configuration** | `testBuildWithTlsSchemeUsesTlsServerProtocol`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, `testBuildRecognizesTheTlsSchemeInAnyCase` |
| **TLS peer verification stays on unless explicitly disabled** | `testTlsVerifyPeerStaysOnUnlessExplicitlyDisabled` (data provider: false/0/no/off disable it; recognized truthy, unrecognized, empty, null and non-scalar values keep it on), `testTlsVerifyPeerInTheDsnStaysOnUnlessExplicitlyDisabled` (DSN query: left out, empty, unrecognized, false, off) |
| **Authentication** | `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent`, `testBuildNormalizesStringBooleanAndNullableStringOptions`, `testBuildNormalizesIntegerBooleanOptions`, `testBuildPassesScalarCredentialOptionsAsStrings` (int, float, true), `testBuildFallsBackToTheDsnCredentialsForNonScalarOptions` |
| **Option coercion edge cases** | `testBuildWithNonScalarOptionsCoerceToSafeDefaults`, `testBuildCoercesNonZeroIntegerBooleanOptionToTrue`, `testBuildCoercesUppercaseBooleanStringToTrue`, `testBuildWithPathMissingTopicThrowsException` |
| **Scheduled messages** | `testBuildWithScheduledMessagesEnabledSetsFlag`, `testBuildWithScheduledMessagesDisabledByDefault`, `testBuildWithScheduledMessagesFromDsnQueryString` |
| **Acknowledgement (ack_sync)** | `testBuildWithAckSyncEnabledSetsFlag`, `testBuildWithAckSyncDisabledByDefault`, `testBuildWithAckSyncFromDsnQueryString` |
| **Deduplication (`deduplicate`)** | `testDeduplicateIsOffByDefaultAndCanBeEnabled` (default, options, DSN query, options over query), `testReadmeConfigurationOptionsAreAccepted` |
| **Extended stream/consumer options + auto_setup** | `testBuildAcceptsAndNormalizesNewStreamAndConsumerOptions`, `testBuildLeavesNewOptionsUnsetByDefault`, `testBuildParsesNewOptionsFromDsnQuery`, `testBuildWithInvalidRetentionThrowsException`, `testBuildWithInvalidDiscardThrowsException`, `testBuildWithInvalidCompressionThrowsException`, `testBuildWithInvalidReplayPolicyThrowsException`, `testBuildWithDuplicateWindowExceedingMaxAgeThrowsException`, `testBuildAllowsDuplicateWindowWhenMaxAgeIsUnlimited`, `testBuildAllowsDuplicateWindowEqualToMaxAge`, `testBuildWithInvalidMaxAckPendingThrowsException`, `testBuildWithInvalidStreamMaxConsumersThrowsException`, `testBuildWithZeroStreamMaxMessageSizeThrowsException`, `testBuildWithStreamMaxMessageSizeExceedingInt32ThrowsException`, `testBuildAcceptsStreamMaxMessageSizeAtTheInt32Boundary`, `testBuildWithZeroDuplicateWindowThrowsException`, `testBuildWithOverlongStreamDescriptionThrowsException`, `testBuildAcceptsStreamDescriptionAtTheLengthLimit`, `testBuildWithUnrecognizedTriStateBooleanThrowsException`, `testBuildAcceptsEveryRecognizedBooleanTokenForTriStateFlags`, `testBuildTreatsANullMaxAgeAsUnlimitedForTheDuplicateWindow`, `testBuildWithInvalidInactiveThresholdThrowsException` (zero, negative, non-numeric), `testBuildRejectsAnUnrecognizedValueInAnyTriStateFlag` (each later flag, after a boolean `stream_deny_delete`) |
| **NATS-native retry tuning** | `testBuildRetryTuningDefaults`, `testBuildAcceptsNatsRetryTuningOptions`, `testBuildWithNegativeNakDelayThrowsException`, `testBuildWithNonPositiveAckWaitThrowsException`, `testBuildWithNonIntegerMaxDeliverThrowsException`, `testBuildWithNonListBackoffThrowsException`, `testBuildWithNonNumericBackoffElementThrowsException`, `testBuildWithMaxDeliverNotExceedingBackoffThrowsException`, `testBuildWithBackoffFromDsnQueryString`, `testBuildAcceptsZeroAndFractionalBackoffEntries`, `testBuildAcceptsMaxDeliverWithoutBackoff` |
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
| **max_deliver** | NATS stops redelivering a poison message after `max_deliver` attempts (no infinite loop) |
| **Symfony's retry strategy ignored in nats mode** | Symfony's retry strategy is ignored with the NATS retry handler (default 3 retries, `max_deliver: 3`, exactly 3 attempts) |

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
| `request-timeout.php` | against a `no_ack` stream, a send with `request_timeout: 1` gives up after about 1 s although the message is stored, and sending it again with the same deduplication id stores nothing more |
| `keepalive.php` | with `keepalive()` called from a SIGALRM handler every second, a message handled for 5 s on `ack_wait: 2` is not redelivered to a second worker (on 5.2.1 the first alarm broke the worker) |
| `connection-checks.php` | sends after idling past `ping_after_idle` and after `close()` both succeed |

## Mutation Testing

Mutation testing is configured via [Infection](https://infection.github.io/) (`infection.json5`) and run
with `composer test:mutation`. It enforces a minimum MSI of 90% and a minimum covered MSI of 95%; CI runs it
on the PHP 8.5 job and daily (`.github/workflows/mutation.yml`). The suite scores 96.3% MSI and covered
MSI: 826 of 858 mutants killed, with 100% mutation code coverage.

Scores reported before, 100% among them, were not real. Infection runs each mutant in a PHPUnit process
whose bootstrap puts its include interceptor on `file://`; the interceptor dropped the wrapper BypassFinals
puts on top of it, so the client's final classes could not be doubled there, every test that doubles one
failed, and Infection counted the mutant as killed. `tests/bootstrap.php` now takes the interceptor's file
swap over (`MutationTestingBootstrapTest`). In a mutant process the mutated class is therefore compiled from
`.infection/infection/mutant.*.infection.php`, with the original line numbers, so a breakpoint set in `src/`
is not hit there.

Two checks around the run keep the score honest; CI runs both:

- **Before it, `composer test:mutation:canary`** runs Infection with `--noop` and the `TrueValue` and
  `FalseValue` mutators. Its mutants (46 today) change nothing, so every one must escape;
  `scripts/check-mutation-canary.php` fails when one is detected, when none ran, or when the summary is
  missing or malformed. On the old bootstrap it detected 42 of 46.
- **After it, `composer test:mutation:check-log`** (`scripts/check-mutation-log.php`) fails when
  `infection.log` shows a mutant that failed with `ClassIsFinalException`, `cannot extend final class` or
  `Error in bootstrap script`, which the harness causes and no test does. `test:mutation` runs with
  `--log-verbosity=all`, so the log holds every mutant's output.

Both Infection runs pass `--logger-github=false`. On GitHub Actions Infection otherwise turns every escaped
mutant into a warning annotation on its source line: the canary's 46, which escape by design, and the
survivors below, on every run and in every pull request. The real run lists every escaped mutant in the job
log instead (`--show-mutations=max`), and CI uploads `infection.log` as the `infection-log` artifact when a
mutation step fails.

### Surviving mutants

Of the 32 mutants that survive:

- **27 are equivalent**: no input makes them behave differently.
- **3 make no difference with the real client**: its acknowledgement calls do not read the `sid` of the
  message `buildAckMessage()` gives them (2 mutants), and `StreamInfo::fromArray()` takes a stream's
  `subjects` from the same config entry the transport reads first (1 mutant). A test could kill them only by
  asserting on the `sid` its client double receives or by building a `StreamInfo` the client never builds,
  which would pin details rather than behaviour.
- **2 differ about once in a million runs**: they also round a delivery time up when it falls on a whole
  second. A test would need a clock the transport cannot be given.

| Mutant | Why it survives |
|---|---|
| `NatsTransport::send()`: an envelope without a `DelayStamp` has a delay of -1 instead of 0 | -1 is not above 0 either, so the message is not scheduled |
| `send()`: `'+' . $delayMs . ' milliseconds'` without the `+` | PHP reads an unsigned relative time as positive (checked for every delay from 1 ms to 300 s) |
| `send()`: the round-up condition `(int) $deliverAt->format('u') !== 0` without the cast, or compared with -1 (2 mutants) | Both conditions are always true, which differs from the original only when the delivery time has no fraction of a second, about one run in a million; a test would need a clock the transport cannot be given |
| `send()`: the `(string)` cast of a header name removed | PHP turns an integer-like string key into an int key either way |
| `get()`: `rawHeaders !== null && rawHeaders !== ''` with `\|\|` | `NatsHeaders::fromWireBlock()` returns `[]` for null and for `''`, as the other branch does |
| `connectionUsable()`: `$ping->ignore()` removed | `await()` subscribes to the PING, which marks it handled even when the wait gives up |
| `buildAckMessage()`: `sid` 1 or -1 instead of 0 (2 mutants) | The client's ack, ackSync, nak, nakWithDelay, term and inProgress read only the reply subject, so only an assertion on the `sid` itself would see the change |
| `buildManagedStreamConfiguration()`: `streamReplicas() >= 0` instead of `> 0` | `streamReplicas()` is never below 1 |
| `buildUpdatedStreamConfiguration()`: `$streamInfo->subjects` before the config's `subjects` | `StreamInfo::fromArray()` takes `subjects` from the same config entry, and both go through `normalizeSubjects()`; only a `StreamInfo` built by hand with other subjects would show a difference |
| `buildUpdatedStreamConfiguration()`: `streamMaxAgeSeconds() >= 0` instead of `> 0` | 0 seconds is 0 nanoseconds, the value of the other branch |
| `buildUpdatedStreamConfiguration()`: a missing `duplicate_window` read as -1 or 1 instead of 0 (2 mutants) | A positive max age is at least 10^9 nanoseconds, so none of these exceeds it |
| `buildUpdatedStreamConfiguration()`: the window clamped when it equals the max age, too | Clamping it then writes the same value |
| `NatsTransportConfiguration`: the defaults of `batching` (0), `stream_max_age` (-1) and `stream_replicas` (0) (3 mutants) | `max()` clamps each to what the original default gives |
| `NatsTransportConfigurationBuilder::parseStreamAndTopic()`: the empty-segment check (3 mutants) | After `trim($path, '/')`, a path that splits into exactly two segments has no empty one |
| `assertPositiveNumber()`, `assertNonNegativeNumber()`: `ceil()` or `round()` instead of `floor()` (4 mutants) | All three return the number itself exactly when it is whole |
| `assertStreamDescriptionLength()`: the early return for a null description removed | null reads as `''`, which passes the length check |
| `assertBackoff()`: `$value < 0` without the `(float)` cast | PHP 8 compares a numeric string with 0 as a number |
| `assertBackoff()`: the int, float and string checks negated together | `is_numeric()` already rejects every value that is not an int, a float or a string |
| `assertDuplicateWindowNotExceedingMaxAge()`: the early return for a missing window removed | A missing window reads as 0, which never exceeds a positive max age |
| `assertDuplicateWindowNotExceedingMaxAge()`: a null max age read as -1 instead of 0 | Both mean no age limit there |
| `toNullableString()`: the early return for null removed | null fails the scalar check below and returns null as well |
| `requiredString()`: `&&` instead of `\|\|` | `parseDsn()` already requires a host, and `parse_url()` never gives an empty one |

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
| DSN template `nats-jetstream://[user:password@]host:port/stream-name/topic-name` | `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `createTransport_WithValidDsn_ReturnsNatsTransportInstance` |

### PHP Code Examples

Every fenced ` ```php ` block in `README.md` is additionally syntax-checked by
`ReadmeExamplesTest::testReadmePhpExampleIsSyntacticallyValid` (and the count is pinned by
`testReadmeContainsThePhpExamplesWeExpect`), so a snippet that stops being valid PHP fails CI.

| README Example | Tests |
|---|---|
| Custom serializer extending `AbstractEnveloperSerializer` | `readmeCustomSerializerExample_EncodeDecode_RoundTrips`, `readmeCustomSerializerExample_DecodeInvalidBody_ThrowsException`, `testReadmePhpExampleIsSyntacticallyValid` |
| `$bus->dispatch(new OrderPlaced($orderId), [new DeduplicationIdStamp(...)])` | `testSendUsesAnApplicationDeduplicationIdWithTheOptionOff`, `testReadmePhpExampleIsSyntacticallyValid`, Behat scenario `A message dispatched again with the same deduplication id is stored once` |
| Igbinary serializer configuration example | `createTransport_UsesProvidedSerializer`, `serialize_WithValidEnvelope_ReturnsSerializedString`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `testConstructorWithoutIgbinaryDoesNotCrash` |
| `$bus->dispatch(new MyMessage(), [new DelayStamp(30000)])` | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders` |
| `$transport->getMessageCount()` | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountSumsAckPendingAndPending` |
| Controller dispatching message (`MessageBus`) | `testReadmePhpExampleIsSyntacticallyValid`, `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesPublishWithHeadersWhenHeadersArePresent`, Behat scenario `Complete message flow - send, check stats, consume, verify` |
| Handler using the `#[AsMessageHandler]` attribute | `testReadmePhpExampleIsSyntacticallyValid`, Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `High-volume message processing with file output verification` |
| `symfony console messenger:consume nats_transport` | Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `Partial message consumption with multiple consumers` |
| `symfony console messenger:consume nats_transport --keepalive=10` | Behat scenario `A message handled for longer than ack_wait is not redelivered while keepalive runs` (with `--keepalive=1`), `testKeepaliveFromASignalHandlerQueuesTheAcknowledgementInsteadOfWaiting` |
| `symfony console messenger:setup-transports nats_transport` | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenarios `Setup NATS stream with max age configuration`, `Setup command handles existing streams gracefully`, `Custom consumer name is registered in JetStream` |

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
| `stream_replicas: 1 / 3` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeAuditTransportOptionsAreAccepted` |
| `retry_handler: 'symfony' / 'nats'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildUsesRetryHandlerFromQuery`, functional NAK/TERM scenarios |
| `deduplicate: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testDeduplicateIsOffByDefaultAndCanBeEnabled`, Behat scenario `Distinct messages are all stored with the deduplicate option` |
| `scheduled_messages: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testReadmeScheduledMessagesDsnEnablesFeature`, `testBuildWithScheduledMessagesEnabledSetsFlag` |
| TLS options (all) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, functional TLS/mTLS scenarios |
| Auth options (token, username, password, jwt, nkey) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions` |
| Multi-subject example (shared stream, per-transport options) | `testReadmeMultiSubjectOptionsAreAccepted` |
| `stream_max_age: 2592000` (audit, 30 days) | `testReadmeAuditTransportOptionsAreAccepted` |

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

### Not Testable by Transport (Symfony / NATS responsibility)

| README Example | Reason |
|---|---|
| `framework.messenger.transports` YAML structure | Symfony config parsing |
| Exact Symfony Console formatting for `messenger:consume` / `messenger:setup-transports` | Symfony CLI output is framework-owned even though the transport behavior is exercised functionally |
| Exact NATS CLI output for `nats-server`, `nats stream ...`, `nats consumer ...` | NATS server and CLI output is external to this package |
