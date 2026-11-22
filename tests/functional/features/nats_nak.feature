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

  # A message the transport's igbinary serializer cannot decode, handled as Symfony's own transports handle it: before
  # Symfony Messenger 8.1 the transport TERMs it and throws the decode error, which stops the worker that pulled it;
  # from 8.1 on it reaches the worker as a MessageDecodingFailedException message, which fails and is TERMed when it
  # is rejected. Up to 5.9.0 nats mode NAKed it, and NATS brought it back at once, to every new worker or to the same
  # one, for ever without max_deliver. Now it is delivered once.
  @nak
  Scenario: A message that cannot be decoded is TERMed in nats mode, so no worker gets it again
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler
    And the NATS stream is set up
    And a message the transport cannot decode is published to its subject
    When two messenger consumers run one after the other for 3 seconds each
    Then the message that could not be decoded should have been delivered once and settled
    And only a consumer before Symfony Messenger 8.1 should have stopped, on the decode error

  # An UnrecoverableMessageHandlingException can never succeed: nats mode TERMs it. Up to 5.9.x it NAKed it, and NATS
  # ran the handler again on every delivery max_deliver allowed.
  @nak
  Scenario: An unrecoverable failure runs once in nats mode
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler, max deliver of 3 and Symfony's default retry strategy
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 unrecoverable failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 1 time

  # A message whose __wakeup() raises a warning it suppresses, as legacy code looking for a cache file does, through the
  # transport's igbinary serializer. The warning is the application's: the message is handled and acknowledged. 5.10.0
  # made it a decode failure, and the message was TERMed without its handler running (#122).
  @nak
  Scenario: A message whose __wakeup raises a suppressed warning is handled and acknowledged in nats mode
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler
    And the NATS stream is set up
    And the test files directory is clean
    And I listen for the TERMs of the transport's consumer
    When I send 1 message whose __wakeup raises a suppressed warning
    And I start a messenger consumer
    And I wait for messages to be consumed
    Then the message whose __wakeup raised a warning should have been handled once
    And its one delivery should have been acknowledged, not TERMed

  # Two handlers of one message: one fails for good (an UnrecoverableMessageHandlingException on every run), the other
  # once (a RuntimeException, then it succeeds). Symfony treats such a failure as unrecoverable only when every handler's
  # is, and nats mode follows that rule (#125): the first delivery is NAKed whichever handler runs first, and the second,
  # where the unrecoverable failure is the only one left, is TERMed. In 5.10.0 and 5.10.1 nats mode went by the first
  # exception when Symfony did not request a retry: with the unrecoverable handler first and max_retries 0 it TERMed the
  # first delivery, and the other handler never ran again (earlier releases NAKed every handler failure). With
  # max_retries 3 Symfony retries the first failure itself, and the transport NAKs the delivery in place of
  # the retry copy, whichever exception comes first (row 22 of #123). The copy it leaves out makes it warn that
  # Symfony's retry strategy is on, once: so the rows with max_retries 3 fail if Symfony's retry stops taking place,
  # and those with 0 if Symfony retries what it should not.
  @nak
  Scenario Outline: With two handlers, a mix of an unrecoverable failure and an ordinary one is NAKed in either order, and TERMed once the unrecoverable one is left
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler, max deliver of 3 and max retries of <max_retries>, routing the message of two handlers
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    And I listen for the advisories of the transport's consumer
    When I send a message to two handlers, the first failing "<first>" and the second "<second>"
    And I start a messenger consumer that stops after 2 deliveries
    And I wait for the consumer to finish or timeout
    Then the transport's consumer should have published "MSG_NAKED, MSG_TERMINATED" for stream sequence 1
    And both handlers should have run 2 times
    And the handler that fails once should have succeeded on its second run
    And the consumer should have warned <warnings> that Symfony's retry strategy is on

    Examples:
      | first    | second   | max_retries | warnings |
      | for good | once     | 0           | 0 times  |
      | once     | for good | 0           | 0 times  |
      | for good | once     | 3           | 1 time   |
      | once     | for good | 3           | 1 time   |

  # When every handler fails for good, the failure is unrecoverable, and the first delivery is TERMed.
  @nak
  Scenario: With two handlers that both fail for good, the first delivery is TERMed
    Given NATS server is running
    And I have a messenger transport configured with NATS retry handler, max deliver of 3 and max retries of 0, routing the message of two handlers
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    And I listen for the advisories of the transport's consumer
    When I send a message to two handlers, the first failing "for good" and the second "for good"
    And I start a messenger consumer that stops after 1 delivery
    And I wait for the consumer to finish or timeout
    Then the transport's consumer should have published "MSG_TERMINATED" for stream sequence 1
    And both handlers should have run 1 time
