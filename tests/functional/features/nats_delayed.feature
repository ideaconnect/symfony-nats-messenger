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
