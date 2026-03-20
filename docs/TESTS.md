# Test Coverage Map

This document maps each feature of the Symfony NATS Messenger Bridge to the tests that cover it.

## Unit Tests

### Transport Core (`tests/unit/NatsTransportTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing & validation** | `testConstructorWithValidDsnInitializesTransport`, `testConstructorWithDottedTopicInitializesTransport`, `testConstructorWithInvalidDsnThrowsException`, `testConstructorWithoutPathThrowsException`, `testConstructorWithoutTopicThrowsException`, `testConstructorWithWildcardTopicThrowsException` |
| **Message sending** | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesRequestWithHeadersWhenHeadersArePresent`, `testSendSerializationFailureUsesErrorDetailsStampMessage`, `testSendSerializationFailureRethrowsOriginalExceptionWithoutErrorDetailsStamp` |
| **Publish response validation** | `testSendThrowsWhenJetStreamHeaderPublishReturnsError`, `testAssertJetStreamPublishSucceededThrowsWhenErrorIsString`, `testAssertJetStreamPublishSucceededThrowsWhenErrorPayloadIsMalformed`, `testAssertJetStreamPublishSucceededPassesOnValidAck`, `testAssertJetStreamPublishSucceededPassesOnEmptyPayload`, `testAssertJetStreamPublishSucceededThrowsOnInvalidJson` |
| **Message receiving** | `testGetReturnsDecodedEnvelopeWithHeadersAndMessageId`, `testGetSkipsEmptyPayloadMessages`, `testGetReturnsEmptyArrayWhenConsumerIsMissing`, `testGetReturnsEmptyArrayWhenBatchRequestTimesOut`, `testGetRethrowsUnexpectedJetStreamExceptions`, `testGetDecodeFailureUsesTermWhenReplySubjectExists`, `testGetDecodeFailureDoesNotInvokeRetryHandlingWithoutReplySubject`, `testGetDecodeFailureUsesNakWhenRetryHandlerIsNats`, `testGetWithMultipleValidMessagesReturnsAll`, `testGetWithBatchingConfigPassesBatchSizeToFetchBatch` |
| **ACK / reject** | `testFindReceivedStampReturnsTransportStamp`, `testAckWithoutTransportStampThrowsException`, `testAckAcknowledgesReceivedEnvelope`, `testRejectWithoutTransportStampThrowsException`, `testRejectUsesTermByDefault`, `testRejectUsesNakWhenRetryHandlerIsNats` |
| **Retry handler (TERM / NAK)** | `testHandleFailedDeliveryUsesTermByDefault`, `testHandleFailedDeliveryUsesNakWhenRetryHandlerIsNats`, `testHandleFailedDeliveryUsesBaseTermTransportPath`, `testHandleFailedDeliveryUsesBaseNakTransportPath`, `testConstructorWithInvalidRetryHandlerThrowsException` |
| **Stream setup (create)** | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupCreatesNewStreamWithMaxMessages`, `testSetupCreatesNewStreamWithMaxMessagesPerSubject` |
| **Stream setup (update existing)** | `testSetupUpdatesStreamWhenItAlreadyExists`, `testSetupUpdatesStreamWhenAlreadyInUseMessage`, `testSetupUpdatesStreamWhenAlreadyExistsInMessage`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, `testSetupUpdatesExistingStreamWithoutDuplicatingSubjects`, `testSetupUpdatesExistingStreamWithMaxMessages`, `testSetupUpdatesExistingStreamWithMaxMessagesPerSubject` |
| **Stream setup (error handling)** | `testSetupDoesNotTreatGenericBadRequestAsExistingStream`, `testSetupChecksStreamExistenceBeforeUpdatingOnAmbiguousBadRequest`, `testSetupWrapsUnexpectedStreamCreationErrors`, `testSetupRethrowsNon404JetStreamExceptionFromStreamExistsCheck`, `testSetupWrapsConsumerCreationError`, `testSetupUpdateStreamFailureWrapsException` |
| **Consumer validation** | `testSetupRejectsUnexpectedConsumerConfiguration`, `testAssertConsumerMatchesConfigurationRejectsUnexpectedConfig`, `testAssertConsumerMatchesConfigurationRejectsWrongDeliverPolicy`, `testAssertConsumerMatchesConfigurationRejectsWrongFilterSubject`, `testAssertConsumerMatchesConfigurationRejectsWrongStreamOrConsumerName` |
| **Message count** | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountReturnsAckPendingWhenHigherThanPending` |
| **Scheduled / delayed messages** | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders`, `testSendWithDelayStampButScheduledMessagesDisabledPublishesNormally`, `testSendWithZeroDelayPublishesNormally`, `testSendWithNegativeDelayPublishesNormally`, `testSendWithDelayStampAndExistingHeadersMergesScheduleHeaders`, `testSetupWithScheduledMessagesAddsDelayedSubjectAndFlag`, `testSetupUpdateStreamWithScheduledMessagesIncludesDelayedSubject` |
| **Igbinary fallback** | `testConstructorWithoutIgbinaryDoesNotCrash` |
| **TLS DSN** | `testConstructorWithTlsDsnInitializesTransport` |

### Transport Factory (`tests/unit/NatsTransportFactoryTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN scheme support** | `supports_WithNatsJetStreamScheme_ReturnsTrue`, `supports_WithNatsJetStreamSchemeAndComplexDsn_ReturnsTrue`, `supports_WithNatsJetStreamTlsScheme_ReturnsTrue`, `supports_WithDifferentScheme_ReturnsFalse`, `supports_WithNatsButNotJetStream_ReturnsFalse`, `supports_WithAmqpScheme_ReturnsFalse`, `supports_WithEmptyString_ReturnsFalse`, `supports_WithHttpScheme_ReturnsFalse` |
| **Transport creation** | `createTransport_WithValidDsn_ReturnsNatsTransportInstance`, `createTransport_WithOptions_PassesOptionsToTransport`, `createTransport_UsesProvidedSerializer`, `createTransport_WithDefaultPort_ParsesDsnCorrectly`, `createTransport_WithoutAuth_ParsesDsnCorrectly`, `createTransport_WithQueryParams_ParsesConfigCorrectly` |

### Configuration Builder (`tests/unit/Options/NatsTransportConfigurationBuilderTest.php`)

| Feature | Tests |
|---------|-------|
| **DSN parsing** | `testBuildWithValidDsnReturnsConfiguration`, `testBuildWithoutPathThrowsException`, `testBuildWithoutTopicThrowsException`, `testBuildWithExtraPathSegmentsThrowsException`, `testBuildWithMalformedDsnThrowsException`, `testBuildWithDsnMissingHostThrowsException`, `testBuildWithDottedTopicNameSucceeds` |
| **Option merging (query + options)** | `testBuildOptionsOverrideQueryOptions`, `testBuildMethodOptionsOverrideQueryForStreamStorageAndPerSubjectLimit`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues` |
| **Validation** | `testBuildWithEmptyConsumerThrowsException`, `testBuildWithInvalidBatchingThrowsException`, `testBuildWithNonNumericBatchingThrowsException`, `testBuildWithNegativeBatchingThrowsException`, `testBuildWithNonIntegerBatchingFloatThrowsException`, `testBuildWithArrayBatchingThrowsException`, `testBuildWithInvalidConnectionTimeoutThrowsException`, `testBuildWithNegativeConnectionTimeoutThrowsException`, `testBuildWithNonNumericConnectionTimeoutThrowsException`, `testBuildWithZeroMaxBatchTimeoutThrowsException`, `testBuildWithNegativeMaxBatchTimeoutThrowsException`, `testBuildWithNonNumericMaxBatchTimeoutThrowsException`, `testBuildWithInvalidStreamReplicaCountThrowsException`, `testBuildWithNegativeStreamReplicasThrowsException`, `testBuildWithNonIntegerStreamReplicasThrowsException`, `testBuildWithNonNumericStreamMaxAgeThrowsException`, `testBuildWithInvalidStreamStorageThrowsException`, `testBuildWithWildcardInStreamNameThrowsException`, `testBuildWithSpaceInTopicThrowsException`, `testBuildWithDotInStreamNameThrowsException`, `testBuildWithGreaterThanInTopicThrowsException` |
| **Stream max messages validation** | `testBuildWithNegativeStreamMaxMessagesThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesThrowsException`, `testBuildWithStreamMaxMessagesFromQueryString` |
| **Stream max messages per subject validation** | `testBuildWithNegativeStreamMaxMessagesPerSubjectThrowsException`, `testBuildWithNonIntegerStreamMaxMessagesPerSubjectThrowsException` |
| **Stream max bytes validation** | `testBuildWithNegativeStreamMaxBytesThrowsException` |
| **Connection timeout propagation** | `testBuildWithConnectionTimeoutPropagatesMs` |
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
| **Igbinary serializer** | `serialize_WithValidEnvelope_ReturnsSerializedString`, `serialize_WithEnvelopeContainingStamps_PreservesStamps`, `deserialize_WithValidSerializedData_ReturnsOriginalData`, `deserialize_WithSerializedEnvelope_ReturnsEnvelope`, `deserialize_WithInvalidData_ReturnsNull`, `deserialize_WithEmptyString_ReturnsFalse`, `encode_WithValidEnvelope_ReturnsArrayWithBody`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `decode_WithEmptyBody_ThrowsMessageDecodingFailedException`, `decode_WithMissingBody_ThrowsMessageDecodingFailedException`, `decode_WithInvalidSerializedData_ThrowsMessageDecodingFailed` |

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
| **Message eviction** | Stream evicts oldest messages when max messages limit is exceeded |

### Consumer (`tests/functional/features/nats_consumer.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Custom consumer name** | Send and consume messages with a custom consumer name |
| **Consumer name verification** | Custom consumer name is registered in JetStream |

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

### Delayed Messages (`tests/functional/features/nats_delayed.feature`)

| Feature | Scenarios |
|---------|-----------|
| **Scheduled delivery** | Delayed messages are delivered after the scheduled time |

### TLS (`tests/functional/features/nats_tls.feature`, `nats_mtls.feature`)

| Feature | Scenarios |
|---------|-----------|
| **TLS connection** | TLS server connection with native PHP serializer, TLS server connection with igbinary serializer |
| **mTLS connection** | mTLS server connection with client certificate |

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

| README Example | Tests |
|---|---|
| Custom serializer extending `AbstractEnveloperSerializer` | `readmeCustomSerializerExample_EncodeDecode_RoundTrips`, `readmeCustomSerializerExample_DecodeInvalidBody_ThrowsException` |
| Igbinary serializer configuration example | `createTransport_UsesProvidedSerializer`, `serialize_WithValidEnvelope_ReturnsSerializedString`, `decode_WithValidEncodedEnvelope_ReturnsEnvelope`, `testConstructorWithoutIgbinaryDoesNotCrash` |
| `$bus->dispatch(new MyMessage(), [new DelayStamp(30000)])` | `testSendWithDelayStampPublishesToDelayedSubjectWithScheduleHeaders` |
| `$transport->getMessageCount()` | `testGetMessageCountReturnsConsumerPendingMessages`, `testGetMessageCountFallsBackToStreamState`, `testGetMessageCountReturnsZeroWhenLookupsFail`, `testGetMessageCountReturnsAckPendingWhenHigherThanPending` |
| Controller dispatching message (`MessageBus`) | `testSendPublishesEncodedBodyWithoutHeaders`, `testSendUsesRequestWithHeadersWhenHeadersArePresent`, Behat scenario `Complete message flow - send, check stats, consume, verify` |
| Handler implementing `MessageHandlerInterface` | Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `High-volume message processing with file output verification` |
| `symfony console messenger:consume nats_transport` | Behat scenarios `Complete message flow - send, check stats, consume, verify`, `Send and consume messages with a custom consumer name`, `Partial message consumption with multiple consumers` |
| `symfony console messenger:setup-transports nats_transport` | `testSetupCreatesStreamAndConsumer`, `testSetupPassesConfiguredStreamOptions`, `testSetupUpdatesExistingStreamMergesSubjectsAndPreservesServerConfig`, Behat scenarios `Setup NATS stream with max age configuration`, `Setup command handles existing streams gracefully`, `Custom consumer name is registered in JetStream` |

### Configuration Option Examples (YAML)

| README Option | Tests |
|---|---|
| `consumer: 'my-consumer'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildWithValidDsnReturnsConfiguration` |
| `batching: 1 / 5 / 10 / 20 / 50` | `testReadmeBatchingExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `max_batch_timeout: 0.5 / 1.0 / 2.0` | `testReadmeTimeoutExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `connection_timeout: 1.0 / 2.0 / 3.0` | `testReadmeTimeoutExamplesAreAccepted`, `testBuildWithConnectionTimeoutPropagatesMs` |
| `stream_max_age: 0 / 86400` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_bytes: 1073741824` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_messages: 1000000` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_max_messages_per_subject: 1000` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeConfigurationOptionsAreAccepted` |
| `stream_storage: 'file' / 'memory'` | `testReadmeStreamRetentionExamplesAreAccepted`, `testBuildWithStreamStorageAndPerSubjectLimitNormalizesValues` |
| `stream_replicas: 1 / 3` | `testReadmeStreamRetentionExamplesAreAccepted`, `testReadmeAuditTransportOptionsAreAccepted` |
| `retry_handler: 'symfony' / 'nats'` | `testReadmeConfigurationOptionsAreAccepted`, `testBuildUsesRetryHandlerFromQuery`, functional NAK/TERM scenarios |
| `scheduled_messages: false / true` | `testReadmeConfigurationOptionsAreAccepted`, `testReadmeScheduledMessagesDsnEnablesFeature`, `testBuildWithScheduledMessagesEnabledSetsFlag` |
| TLS options (all) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions`, functional TLS/mTLS scenarios |
| Auth options (token, username, password, jwt, nkey) | `testBuildWithTlsAndAuthOptionsPropagatesToNatsOptions` |
| `delay: 0.5 / 1` (multi-subject example) | `testReadmeMultiSubjectOptionsAreAccepted` |
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
