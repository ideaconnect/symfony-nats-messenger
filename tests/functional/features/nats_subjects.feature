Feature: Per-message subjects
  The topic in the DSN is the default publish subject. A NatsSubjectStamp publishes one message to another
  subject, and additional_subjects adds the patterns the stream and the one durable consumer cover next to the
  topic. Verification: the consumer's filters read from JetStream, marker files written by the handlers, and the
  subjects of the messages stored in the stream.

  Background:
    Given NATS server is running

  @subjects
  Scenario: One consumer reads messages published to the topic and to a stamped subject
    Given I have a messenger transport configured with additional subjects "test.messages.updated.>"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages to the transport
    And I send 3 messages to the subject "test.messages.updated.42" starting at message 4
    Then the durable consumer "client" should filter on "test.messages, test.messages.updated.>"
    And the NATS stream should hold 3 messages on subject "test.messages"
    And the NATS stream should hold 3 messages on subject "test.messages.updated.42"
    When I start 1 consumer that processes 6 messages
    And I wait for the consumers to finish
    Then 6 messages should have been processed by the consumers
    And the test files directory should contain exactly 6 files

  # The scheduled copy waits on test.messages.delayed.<uuid>, which the consumer's filters do not match, so it is
  # neither counted nor delivered before it is due.
  @subjects @delayed
  Scenario: A delayed stamped message is delivered once, to its subject, after the scheduled time
    Given I have a messenger transport configured with scheduled messages and additional subjects "test.messages.updated.>"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages with 3000 milliseconds delay to the subject "test.messages.updated.42"
    Then the messenger stats should show 0 messages waiting
    When I start 1 consumer that processes 3 messages
    And I wait for the consumers to finish
    Then 3 messages should have been processed by the consumers
    And the test files directory should contain exactly 3 files
    And the NATS stream should hold 3 messages on subject "test.messages.updated.42"

  # The stamp is not sent with the message; the retry goes to the subject the message arrived on.
  @subjects
  Scenario: Symfony's retry of a stamped message keeps its subject
    Given I have a messenger transport with failure transport configured and additional subjects "test.messages.updated.>"
    And the NATS stream is set up
    And the failure stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 retryable failing message to the subject "test.messages.updated.42"
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then the retryable message should have been processed successfully
    And the NATS stream should hold 2 messages on subject "test.messages.updated.42"
    And the NATS stream should hold 0 messages on subject "test.messages"
    And the failure transport should contain 0 messages

  # CONSUMER.CREATE is an upsert on NATS 2.10 and later: setup() of a transport whose durable consumer already
  # exists rewrites its filters to the topic plus additional_subjects, in both directions, and nothing fails.
  @subjects
  Scenario: Setup widens the filters of an existing consumer when a pattern is added
    Given I have a messenger transport configured with max age of 15 minutes using "messenger.transport.native_php_serializer"
    And the NATS stream is set up
    Then the durable consumer "client" should filter on "test.messages"
    Given I have a messenger transport configured with additional subjects "test.messages.updated.>"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the durable consumer "client" should filter on "test.messages, test.messages.updated.>"
    When I send 3 messages to the subject "test.messages.updated.42" starting at message 1
    Then the messenger stats should show 3 messages waiting

  # The messages stored on the removed pattern stay in the stream, but the narrowed consumer no longer sees them.
  @subjects
  Scenario: Setup narrows the filters of an existing consumer when a pattern is removed
    Given I have a messenger transport configured with additional subjects "test.messages.updated.>"
    And the NATS stream is set up
    When I send 3 messages to the subject "test.messages.updated.42" starting at message 1
    Then the durable consumer "client" should filter on "test.messages, test.messages.updated.>"
    And the messenger stats should show 3 messages waiting
    Given I have a messenger transport configured with max age of 15 minutes using "messenger.transport.native_php_serializer"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the durable consumer "client" should filter on "test.messages"
    And the messenger stats should show 0 messages waiting
    And the NATS stream should hold 3 messages on subject "test.messages.updated.42"

  # The consumer keeps its position: a pattern added later does not replay the messages the stream already held on
  # it once the consumer has moved past them.
  @subjects
  Scenario: A pattern added after the consumer moved past its stored messages does not replay them
    Given I have a messenger transport configured with additional subjects "test.messages.updated.>"
    And the NATS stream is set up
    And the test files directory is clean
    Given I have a messenger transport configured with max age of 15 minutes using "messenger.transport.native_php_serializer"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the durable consumer "client" should filter on "test.messages"
    When I send 3 messages to the subject "test.messages.updated.42" starting at message 1
    And I send 3 messages to the transport
    Then the messenger stats should show 3 messages waiting
    When I start 1 consumer that processes 3 messages
    And I wait for the consumers to finish
    Then 3 messages should have been processed by the consumers
    Given I have a messenger transport configured with additional subjects "test.messages.updated.>"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the durable consumer "client" should filter on "test.messages, test.messages.updated.>"
    And the messenger stats should show 0 messages waiting
    And the NATS stream should hold 3 messages on subject "test.messages.updated.42"
