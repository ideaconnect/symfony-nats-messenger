Feature: Custom NATS Consumer
  Tests that the consumer DSN option creates a named JetStream consumer
  and that messages flow correctly through it. Verification: messages
  are sent, consumed, and counted via console output and marker files.

  Background:
    Given NATS server is running

  @consumer
  Scenario: Send and consume messages with a custom consumer name
    Given I have a messenger transport configured with consumer name "my-custom-consumer"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @consumer
  Scenario: Custom consumer name is registered in JetStream
    Given I have a messenger transport configured with consumer name "verified-consumer"
    And the NATS stream is set up
    Then the NATS stream should have a consumer named "verified-consumer"
