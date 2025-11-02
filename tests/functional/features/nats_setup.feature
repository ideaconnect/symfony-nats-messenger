Feature: NATS Stream Setup
  In order to use NATS Messenger transport
  As a developer
  I need to be able to setup NATS streams with specific configuration

  Background:
    Given NATS server is running

  Scenario: Setup NATS stream with max age configuration
    Given I have a messenger transport configured with max age of 15 minutes
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have a max age of 15 minutes
    And the stream should be configured with the correct subject

  Scenario: Setup command handles existing streams gracefully
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream already exists
    When I run the messenger setup command
    Then the setup should complete successfully
    And the existing stream configuration should be preserved

  Scenario: Setup command fails gracefully when NATS is unavailable
    Given NATS server is not running
    And I have a messenger transport configured with max age of 15 minutes
    When I run the messenger setup command
    Then the setup should fail with a connection error
    And the error message should be descriptive

  @flow
  Scenario: Complete message flow - send, check stats, consume, verify
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream is set up

    When I send 20 messages to the transport
    Then the messenger stats should show 20 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 20 messages should be consumed
    And the messenger stats should show 0 messages waiting

  Scenario: Partial message consumption with multiple consumers
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream is set up
    When I run the messenger setup command
    Then the setup should complete successfully
    When I send 20 messages to the transport
    Then the messenger stats should show 20 messages waiting
    When I start 2 consumers that each process 5 messages
    And I wait for the consumers to finish
    Then 10 messages should have been processed by the consumers

  @high
  Scenario: High-volume message processing with file output verification
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream is set up
    And the test files directory is clean
    When I send 10000 messages to the transport
    Then the messenger stats should show 10000 messages waiting
    When I start 4 consumers that each process 1000 messages
    And I wait for the consumers to finish
    Then the test files directory should contain approximately 4000 files
    And the messenger stats should show approximately 6000 messages waiting
    When I start 2 consumers that each process 1500 messages
    And I wait for the consumers to finish
    Then the messenger stats should show approximately 3000 messages waiting
    And the test files directory should contain approximately 7000 files
    When I start 3 consumers that each process 1000 messages
    And I wait for the consumers to finish
    Then the messenger stats should show approximately 0 messages waiting
    And the test files directory should contain approximately 10000 files
