Feature: NATS Batching
  Tests that the batching DSN option is accepted and messages are delivered
  correctly when the consumer fetches multiple messages per JetStream request.
  Verification: all sent messages are consumed and counted via console output
  and marker-file checks.

  Background:
    Given NATS server is running

  @batching
  Scenario: Consume messages with batching of 5
    Given I have a messenger transport configured with batching of 5
    And the NATS stream is set up
    And the test files directory is clean
    When I send 10 messages to the transport
    Then the messenger stats should show 10 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 10 messages should be consumed

  @batching
  Scenario: Consume fewer messages than batch size
    Given I have a messenger transport configured with batching of 10
    And the NATS stream is set up
    And the test files directory is clean
    When I send 2 messages to the transport
    Then the messenger stats should show 2 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 2 messages should be consumed
