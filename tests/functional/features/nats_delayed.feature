Feature: Delayed Messages via NATS Scheduled Messages
  Tests that DelayStamp-based scheduling works with NATS JetStream's native
  scheduled-message support. Messages sent with a delay should not be available
  to consumers until the scheduled time elapses. Verification: marker-file
  counting and (for timing tests) stats polling during the delay window.

  Background:
    Given NATS server is running

  @delayed
  Scenario: Delayed messages are delivered after the scheduled time
    Given I have a messenger transport configured with scheduled messages enabled
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages with 3000 milliseconds delay to the transport
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then all 3 messages should be consumed
    And the test files directory should contain 3 files

  @delayed
  Scenario: Delayed messages are not available to the consumer before the scheduled time
    Given I have a messenger transport configured with scheduled messages enabled
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages with 3000 milliseconds delay to the transport
    Then the messenger stats should show 0 messages waiting
    When I start 1 consumer that processes 3 messages
    And I wait for the consumers to finish
    Then 3 messages should have been processed by the consumers
    And the test files directory should contain exactly 3 files

  @delayed
  Scenario: A larger batch of delayed messages all arrive after the scheduled time
    Given I have a messenger transport configured with scheduled messages enabled
    And the NATS stream is set up
    And the test files directory is clean
    When I send 10 messages with 2000 milliseconds delay to the transport
    Then the messenger stats should show 0 messages waiting
    When I start 1 consumer that processes 10 messages
    And I wait for the consumers to finish
    Then 10 messages should have been processed by the consumers
    And the test files directory should contain exactly 10 files

  @delayed
  Scenario: Delayed messages are load-balanced across multiple consumers
    Given I have a messenger transport configured with scheduled messages enabled
    And the NATS stream is set up
    And the test files directory is clean
    When I send 12 messages with 2000 milliseconds delay to the transport
    Then the messenger stats should show 0 messages waiting
    When I start 3 consumers that each process 4 messages
    And I wait for the consumers to finish
    Then 12 messages should have been processed by the consumers
    And the test files directory should contain exactly 12 files

  # The refusal comes before setup writes the stream: NATS never turns allow_msg_schedules off once a write set it, so
  # with the stream written first, setup with scheduled_messages turned off, the first remedy the refusal names,
  # failed with "message schedules can not be disabled".
  @delayed
  Scenario: scheduled_messages on an interest stream nothing has interest in the delayed subjects of is refused
    Given I have a messenger transport configured with scheduled messages on a stream with interest retention
    When I run the messenger setup command
    Then the setup should fail saying "the stream has interest retention and no consumer has interest in"
    And the NATS stream should not exist
    When I have a messenger transport configured with a stream with interest retention and scheduled messages off
    And I run the messenger setup command
    Then the NATS stream should be created successfully

  # A stream set up outside the transport: a worker checks it before its first pull, since it sends Symfony's delayed
  # retries, and stops with the reason instead of losing them.
  @delayed
  Scenario: A worker refuses to pull from an interest stream nothing has interest in the delayed subjects of
    Given I have a messenger transport configured with scheduled messages on a stream with interest retention
    And the stream already exists with interest retention and nothing on its delayed subjects
    Then a messenger consumer should fail saying "no consumer has interest in"

  @delayed
  Scenario: Delayed messages on an interest stream are delivered while a consumer has interest in the delayed subjects
    Given I have a messenger transport configured with scheduled messages on a stream with interest retention
    And the stream already has a consumer on the delayed subjects that nobody pulls from
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages with 2000 milliseconds delay to the transport
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then all 3 messages should be consumed
    And the test files directory should contain 3 files
