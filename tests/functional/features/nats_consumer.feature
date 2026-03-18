Feature: Custom NATS Consumer
  In order to use named consumers
  As a developer
  I need to configure a custom consumer name

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
