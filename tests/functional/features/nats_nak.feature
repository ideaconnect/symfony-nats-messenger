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

  # Symfony's retry strategy left at its framework default (3 retries). Symfony Messenger 7.4.22, 8.1.10 and
  # later acknowledge a message they sent for retry instead of rejecting it, and in nats mode the transport does
  # not publish that retry: it NAKs the original on that acknowledgement, so NATS still redelivers it. Before
  # that, the message was acknowledged and lost after its first failure (#90).
  @nak
  Scenario: A message that fails once is redelivered with Symfony's default retry strategy
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler, max deliver of 3 and Symfony's default retry strategy
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 retryable failing message
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then the retryable message should have been processed successfully

  # A message the transport's igbinary serializer cannot decode: before Symfony Messenger 8.1 it stops the worker that
  # pulls it, as Symfony's own transports do; from 8.1 on it reaches the worker as a MessageDecodingFailedException
  # message, which fails. Up to 5.9.0 nats mode NAKed it either way, and NATS brought it back at once, to every new
  # worker or to the same one, for ever without max_deliver. It is TERMed now: delivered once, and gone.
  @nak
  Scenario: A message that cannot be decoded is TERMed in nats mode, so no worker gets it again
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler
    And the NATS stream is set up
    And a message the transport cannot decode is published to its subject
    When two messenger consumers run one after the other for 3 seconds each
    Then the message that could not be decoded should have been delivered once and settled
