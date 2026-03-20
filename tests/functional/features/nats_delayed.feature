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
