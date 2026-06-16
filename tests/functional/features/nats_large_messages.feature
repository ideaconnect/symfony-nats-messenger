Feature: Large Messages
  Tests that large serialized payloads round-trip through NATS JetStream intact:
  a single consumer drains them, and multiple consumers load-balance them with each
  message processed exactly once. Verification: marker-file counting (the handler
  refuses to overwrite an existing file, so a duplicate delivery would fail the run).

  Background:
    Given NATS server is running

  @large-messages
  Scenario Outline: Round-trip large messages through a single consumer
    Given I have a messenger transport configured with max age of 15 minutes using "<serializer>"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 10 messages of 128 KB to the transport
    Then the messenger stats should show 10 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 10 messages should be consumed
    And the test files directory should contain 10 files

    Examples:
      | serializer                                |
      | messenger.transport.native_php_serializer |
      | igbinary_serializer                       |

  @large-messages
  Scenario: Large messages are load-balanced across consumers, each processed exactly once
    Given I have a messenger transport configured with max age of 15 minutes using "igbinary_serializer"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 20 messages of 64 KB to the transport
    Then the messenger stats should show 20 messages waiting
    When I start 2 consumers that each process 10 messages
    And I wait for the consumers to finish
    Then 20 messages should have been processed by the consumers
    And the test files directory should contain exactly 20 files
