Feature: NATS Stream Setup
  In order to use NATS Messenger transport
  As a developer
  I need to be able to setup NATS streams with specific configuration

  Background:
    Given NATS server is running

  @maxage
  Scenario Outline: Setup NATS stream with max age configuration
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have a max age of 15 minutes
    And the stream should be configured with the correct subject

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |

  @existing
  Scenario Outline: Setup command handles existing streams gracefully
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    And the NATS stream already exists
    When I run the messenger setup command
    Then the setup should complete successfully
    And the existing stream configuration should be preserved

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |

  Scenario: Setup command fails gracefully when NATS is unavailable
    Given NATS server is not running
    And I have a messenger transport configured with max age of 15 minutes using "igbinary_serializer"
    When I run the messenger setup command
    Then the setup should fail with a connection error
    And the error message should be descriptive

  @flow
  Scenario Outline: Complete message flow - send, check stats, consume, verify
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    And the NATS stream is set up

    When I send 10 messages to the transport
    Then the messenger stats should show 10 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 10 messages should be consumed
    And the messenger stats should show 0 messages waiting

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |

  @partial
  Scenario Outline: Partial message consumption with multiple consumers
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    And the NATS stream is set up
    When I run the messenger setup command
    Then the setup should complete successfully
    When I send 10 messages to the transport
    Then the messenger stats should show 10 messages waiting
    When I start 2 consumers that each process 3 messages
    And I wait for the consumers to finish
    Then 6 messages should have been processed by the consumers

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |

  @high
  Scenario Outline: High-volume message processing with file output verification
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 1000 messages to the transport
    Then the messenger stats should show 1000 messages waiting
    When I start 2 consumers that each process 200 messages
    And I wait for the consumers to finish
    Then the test files directory should contain approximately 400 files
    And the messenger stats should show approximately 600 messages waiting
    When I start 3 consumers that each process 200 messages
    And I wait for the consumers to finish
    Then the messenger stats should show approximately 0 messages waiting
    And the test files directory should contain approximately 1000 files

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |