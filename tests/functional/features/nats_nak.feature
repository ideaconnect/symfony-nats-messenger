Feature: NATS-native retry via NAK
  Tests the NATS retry handler path: when retry_handler=nats, failed messages
  are NAK'd (negatively acknowledged) so NATS redelivers them. Verification:
  a retryable message handler writes a marker file on its second attempt,
  proving the message was redelivered and succeeded after initial failure.

  @nak
  Scenario: Message retry handled by NATS via NAK
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 retryable failing message
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then the retryable message should have been processed successfully
