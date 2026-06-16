Feature: Synchronous acknowledgement (ack_sync)
  Tests the ack_sync option: when enabled, the transport acknowledges each message
  with JetStream's double-ack (ackSync), waiting for server confirmation. Verification:
  messages are consumed and the queue drains to zero, proving the confirmed-ACK path
  completes the receive lifecycle normally.

  Background:
    Given NATS server is running

  @ack-sync
  Scenario: Messages are consumed with synchronous acknowledgement
    Given I have a messenger transport configured with synchronous acknowledgement
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed
    And the messenger stats should show 0 messages waiting
