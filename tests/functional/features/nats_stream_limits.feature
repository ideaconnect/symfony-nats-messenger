Feature: NATS Stream Limits
  Tests stream resource-limit configuration: max_bytes, max_msgs, and
  max_msgs_per_subject. Covers both fresh creation and idempotent update
  of existing streams. Verification queries the JetStream API to confirm
  the NATS server stored the expected limit values.

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

  @limits
  Scenario: Stream evicts oldest messages when max messages limit is exceeded
    Given I have a messenger transport configured with stream max messages of 5
    And the NATS stream is set up
    And the test files directory is clean
    When I send 10 messages to the transport
    Then the NATS stream should contain at most 5 messages
