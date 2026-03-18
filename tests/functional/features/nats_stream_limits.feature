Feature: NATS Stream Limits
  In order to control stream resource usage
  As a developer
  I need to configure stream max bytes and max messages

  Background:
    Given NATS server is running

  @limits
  Scenario: Setup stream with max bytes limit
    Given I have a messenger transport configured with stream max bytes of 1048576
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have max bytes of 1048576

  @limits
  Scenario: Setup stream with max messages limit
    Given I have a messenger transport configured with stream max messages of 100
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have max messages of 100
