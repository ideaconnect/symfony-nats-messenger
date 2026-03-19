# Test Coverage Map

This document maps each feature of the Symfony NATS Messenger Bridge to the tests that cover it.

## Unit Tests

### Transport Core (`tests/unit/NatsTransportTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing & validation** | `testConstructorWithValidDsnInitializesTransport`, `testConstructorWithDottedTopicInitializesTransport`, `testConstructorWithInvalidDsnThrowsException`, `testConstructorWithoutPathThrowsException`, `testConstructorWithoutTopicThrowsException`, `testConstructorWithWildcardTopicThrowsException` |
| **Message sending** | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesRequestWithHeadersWhenHeadersArePresent`, `testSendSerializationFailureUsesErrorDetailsStampMessage`, `testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp` |
| **Publish response validation** | `testSendThrowsWhenJetStreamHeaderPublishReturnsError`, `testAssertJetStreamPublishSucceededThrowsWhenErrorIsString`, `testAssertJetStreamPublishSucceededThrowsWhenErrorPayloadIsMalformed`, `testAssertJetStreamPublishSucceededPassesOnValidAck`, `testAssertJetStreamPublishSucceededPassesOnEmptyPayload`, `testAssertJetStreamPublishSucceededThrowsOnInvalidJson` |
| **Message receiving** | `testGetReturnsDecodedEnvelopeWithHeadersAndMessageId`, `testGetSkipsEmptyPayloadMessages`, `testGetReturnsEmptyArrayWhenConsumerIsMissing`, `testGetReturnsEmptyArrayWhenBatchRequestTimesOut`, `testGetRethrowsUnexpectedJetStreamExceptions`, `testGetDecodeFailureUsesTermWhenReplySubjectExists`, `testGetDecodeFailureDoesNotInvokeRetryHandlingWithoutReplySubject` |
| **ACK / reject** | `testFindReceivedStampReturnsTransportStamp`, `testAckWithoutTransportStampThrowsException`, `testAckAcknowledgesReceivedEnvelope`, `testRejectWithoutTransportStampThrowsException`, `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats` |
| **Retry handler (TERM / NAK)** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseTermTransportPath`, `testHandleFailedDeliveryUsesBaseNakTransportPath`, `testConstructorWithInvalidRetryHandlerThrowsException` |
| **Stream setup (create)** | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupCreatesNewStreamWithMaxMessages`, `testSetupCreatesNewStreamWithMaxMessagesPerSubject` |
| **Stream setup (update existing)** | `testSetupUpdatesStreamWhenItAlreadyExists`, `testSetupUpdatesStreamWhenAlreadyInUseMessage`, `testSetupUpdatesStreamWhenAlreadyExistsInMessage`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, `testSetupUpdatesExistingStreamWithoutDuplicatingSubjects`, `testSetupUpdatesExistingStreamWithMaxMessages`, `testSetupUpdatesExistingStreamWithMaxMessagesPerSubject` |
| **Stream setup (error handling)** | `testSetupDoesNotTreatGenericBadRequestAsExistingStream`, `testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest`, `testSetupWrapsUnexpectedStreamCreationErrors`, `testSetupRethrowsNon404JetStreamExceptionFromStreamExistsCheck` |
| **Consumer validation** | `testSetupRejectsUnexpectedConsumerConfiguration`, `testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig`, `testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy`, `testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject`, `testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName` |
| **Message count** | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountReturnsAckPendingWhenHigherThanPending` |
| **Scheduled / delayed messages** | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`, `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally`, `testSendWithZeroDelayPublishesNormally`, `testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders`, `testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag`, `testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject` |
| **Igbinary fallback** | `testConstructorWithoutIgbinaryDoesNotCrash` |

### Transport Factory (`tests/unit/NatsTransportFactoryTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN scheme support** | `supports_WithNatsJetStreamScheme_ReturnsTrue`, `supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue`, `supports_WithNatsJetStreamTlsScheme_ReturnsTrue`, `supports_WithDifferentScheme_ReturnsFalse`, `supports_WithNatsButNotJetStream_ReturnsFalse`, `supports_WithAmqpScheme_ReturnsFalse`, `supports_WithEmptyString_ReturnsFalse` |
| **Transport creation** | `createTransport_WithValidDsn_ReturnsNatsTransportInstance`, `createTransport_WithOptions_PassesOptionsToTransport`, `createTransport_UsesProvidedSerializer` |

### Configuration Builder (`tests/unit/Options/NatsTransportConfigurationBuilderTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing** | `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `testBuildWithExtraPathSegmentsThrowsException` |
| **Option merging (query + options)** | `testBuildOptionsOverrideQueryOptions`, `testBuildMethodOptionsOverrideQueryForStreamStorageAndPerSubjectLimit`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues` |
| **Validation** | `testBuildWithEmptyConsumerThrowsException`, `testBuildWithInvalidBatchingThrowsException`, `testBuildWithNonNumericBatchingThrowsException`, `testBuildWithInvalidConnectionTimeoutThrowsException`, `testBuildWithInvalidStreamReplicaCountThrowsException`, `testBuildWithInvalidStreamStorageThrowsException`, `testBuildWithWildcardInStreamNameThrowsException`, `testBuildWithSpaceInTopicThrowsException`, `testBuildWithDotInStreamNameThrowsException`, `testBuildWithGreaterThanInTopicThrowsException` |
| **Stream max messages validation** | `testBuildWithNegativeStreamMaxMessagesThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesThrowsException`, `testBuildWithStreamMaxMessagesFromQueryString` |
| **Stream max messages per subject validation** | `testBuildWithNegativeStreamMaxMessagesPerSubjectThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesPerSubjectThrowsException` |
| **Stream max bytes validation** | `testBuildWithNegativeStreamMaxBytesThrowsException` |
| **Retry handler** | `testBuildUsesRetryHandlerFromQuery`, `testBuildWithInvalidRetryHandlerThrowsException` |
| **TLS configuration** | `testBuildWithTlsSchemeUsesTlsServerProtocol`, `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions` |
| **Authentication** | `testBuildUsesDsnCredentialsAndDefaultPortWhenOverridesAreAbsent`, `testBuildNormalizesStringBooleanAndNullableStringOptions`, `testBuildNormalizesIntegerBooleanOptions` |
| **Scheduled messages** | `testBuildWithScheduledMessagesEnabledSetsFlag`, `testBuildWithScheduledMessagesDisabledByDefault`, `testBuildWithScheduledMessagesFromDsnQueryString` |
| **Option completeness** | `testDefaultOptionsCoversAllTransportOptionCases` |

### Configuration (`tests/unit/Options/NatsTransportConfigurationTest.php`)

| Feature | Tests |
|---------|-------|
| **Type coercion** | `testTypedAccessorsNormalizeScalarValues`, `testTypedAccessorsProvideDefaults`, `testTypedAccessorsTruncateFloatValues` |
| **Scheduled messages accessor** | `testScheduledMessagesAccessorReturnsConstructorValue`, `testScheduledMessagesDefaultsToFalse` |

### Serializers (`tests/unit/Serializer/`)

| Feature | Tests |
|---------|-------|
| **Abstract serializer encode/decode** | `encode_WithValidEnvelope_ReturnsArrayWithBody`, `encode_WithEnvelopeContainingStamps_PreservesStampsInBody`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `decode_WithEmptyBody_ThrowsMessageDecodingFailedException`, `decode_WithMissingBody_ThrowsMessageDecodingFailedException`, `decode_WithNullBody_ThrowsMessageDecodingFailedException`, `decode_WhenDeserializeReturnsNonEnvelope_ThrowsMessageDecodingFailed`, `encode_ThenDecode_ReturnsEquivalentEnvelope`, `encode_WithMultipleStamps_PreservesAllStamps`, `encode_WithValidEnvelope_IncludesHeadersKey` |
| **Igbinary serializer** | `serialize_WithValidEnvelope_ReturnsSerializedString`, `serialize_WithEnvelopeContainingStamps_PreservesStamps`, `deserialize_WithValidSerializedData_ReturnsOriginalData`, `deserialize_WithSerializedEnvelope_ReturnsEnvelope`, `deserialize_WithInvalidData_ReturnsNull`, `deserialize_WithEmptyString_ReturnsNull`, `encode_WithValidEnvelope_ReturnsArrayWithBody`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `decode_WithEmptyBody_ThrowsMessageDecodingFailedException`, `decode_WithMissingBody_ThrowsMessageDecodingFailedException`, `decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed` |

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
| **Full message flow** | Complete message flow — send, check stats, consume, verify (native PHP serializer, igbinary) |
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

### Consumer (`tests/functional/features/nats_consumer.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Custom consumer name** | Send and consume messages with a custom consumer name |

### Batching (`tests/functional/features/nats_batching.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Batch consumption** | Consume messages with batching of 5 |

### Retry Handlers (`tests/functional/features/nats_nak.feature`, `nats_term.feature`)

| Feature | Scenarios |
|---------|-----------|
| **NAK retry** | Message retry handled by NATS via NAK |
| **TERM failure** | Failed message routed to Symfony failure transport via TERM |

### Delayed Messages (`tests/functional/features/nats_delayed.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Scheduled delivery** | Delayed messages are delivered after the scheduled time |

### TLS (`tests/functional/features/nats_tls.feature`, `nats_mtls.feature`)

| Feature | Scenarios |
|---------|-----------|
| **TLS connection** | TLS server connection with native PHP serializer, TLS server connection with igbinary serializer |
| **mTLS connection** | mTLS server connection with client certificate |
