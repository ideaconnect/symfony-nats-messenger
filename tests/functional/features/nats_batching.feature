Feature: NATS Batching
  In order to consume messages efficiently
  As a developer
  I need batching greater than 1 to work correctly

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
