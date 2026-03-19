Feature: Delayed Messages via NATS Scheduled Messages
  In order to schedule message delivery
  As a developer
  I need delayed messages using DelayStamp to be scheduled via NATS JetStream

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
