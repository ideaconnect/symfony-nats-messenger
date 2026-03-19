Feature: NATS Stream Limits
  In order to control stream resource usage
  As a developer
  I need to configure stream max bytes, max messages and max messages per subject

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

  @limits
  Scenario: Setup stream with max messages per subject limit
    Given I have a messenger transport configured with max messages per subject of 50
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have max messages per subject of 50

  @limits
  Scenario: Update existing stream preserves max messages limit
    Given I have a messenger transport configured with stream max messages of 200
    And the NATS stream already exists
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have max messages of 200

  @limits
  Scenario: Update existing stream preserves max messages per subject limit
    Given I have a messenger transport configured with max messages per subject of 25
    And the NATS stream already exists
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have max messages per subject of 25
