Feature: Symfony's retries carried out by NATS (retry_via: nak)
  With retry_handler=symfony and retry_via=nak, Symfony's retry strategy decides whether and when a failed
  message is retried, and the transport carries the retry out by NAKing the failed delivery with Symfony's
  delay instead of publishing a copy: NATS redelivers the original. A NAK delay works on every supported
  server, so the feature is not tagged @delayed and the oldest-server job runs it too.

  Background:
    Given NATS server is running
    And I have a messenger transport configured with NAK retries and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    And the retry state directory is clean
    And the test files directory is clean

  @retry-via
  Scenario: A message that keeps failing is retried through NAKs, then given up on with one failure copy
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the failure transport should contain 1 message
    And the NATS stream should hold 1 message
    And the consumer should not have warned that Symfony's retry strategy is on

  @retry-via
  Scenario: A message that fails once is handled on its NAK retry
    When I send 1 retryable failing message
    And I start a messenger consumer with high limit
    And I wait for messages to be consumed
    Then the retryable message should have been processed successfully
    And the failure transport should contain 0 messages
    And the NATS stream should hold 1 message

  @retry-via
  Scenario: An unrecoverable failure runs once and leaves one failure copy
    When I send 1 unrecoverable failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 1 time
    And the failure transport should contain 1 message

  @retry-via
  Scenario: A consumer whose max_deliver the option does not say still gets the retry of its last delivery
    Given the transport's consumer allows 2 deliveries, which the max_deliver option does not say
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the failure transport should contain 1 message
    And the NATS stream should hold 2 messages
    And the consumer should have warned once that the consumer allows 2 deliveries and the option says none

  # The scenarios below replace the Background's transport with one of their own, and set it up again.

  # max_deliver in the options, as setup() wrote it on the consumer: its second delivery is the last one, so the retry
  # of that delivery goes out as a copy, which runs at once and has deliveries of its own.
  @retry-via
  Scenario: With max_deliver in the options, the retry of the consumer's last delivery goes out as a copy
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak&max_deliver=2", the retry strategy "{ max_retries: 2, delay: 100, multiplier: 2, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 3 deliveries
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the failure transport should contain 1 message
    And the NATS stream should hold 2 messages
    And the consumer should have warned once that the retry of the consumer's last delivery was published as a copy without its delay

  # Symfony retries a RecoverableExceptionInterface whatever max_retries says. The retry of the message's second
  # delivery, the last max_deliver allows, goes out as a copy, and the copy is NAKed and redelivered in its turn.
  @retry-via
  Scenario: A recoverable failure is retried past max_retries and max_deliver until it is handled
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak&max_deliver=2", the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 message that fails with a RecoverableMessageHandlingException on its first 3 runs
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    Then the failing message should have been handled on its run 4
    And the failure transport should contain 0 messages
    And the NATS stream should hold 2 messages

  @retry-via
  Scenario: Each NAK retry waits for the delay Symfony's retry strategy computed
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak", the retry strategy "{ max_retries: 2, delay: 300, multiplier: 2, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 3 deliveries
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the retries of the failing message should have waited at least "300 and 600" ms, and less than 5 s more
    And the failure transport should contain 1 message

  # The server holds a NAK retry, so it survives a restart of the server: the worker that NAKed is gone before the
  # restart, and a new one gets the message once. The server stores the time of a pending delivery in whole seconds,
  # so it can come up to a second early (docs/symfony_retry_via.md).
  @retry-via
  Scenario: A NAK retry that waits survives a restart of the server
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak&ack_wait=2", the retry strategy "{ max_retries: 2, delay: 6000, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 retryable failing message
    And I start a messenger consumer that stops after 1 delivery
    And I wait for the consumer to finish or timeout
    And I restart the NATS server
    Then the transport's consumer should have 1 delivery waiting for an acknowledgement
    When I start a messenger consumer that stops after 2 deliveries or 10 seconds
    And I wait for the consumer to finish or timeout
    Then the retryable message should have been processed successfully
    And the retries of the failing message should have waited at least "5000" ms, and less than 6 s more
    And the failure transport should contain 0 messages

  # A worker that dies while it handles a message settles nothing: the delivery comes back after ack_wait, and the
  # transport counts it as a retry, so with max_retries 1 Symfony gives up on the second run.
  @retry-via
  Scenario: A delivery nobody settled comes back after ack_wait and counts as a retry
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak&ack_wait=1", the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 always-failing message that crashes the worker on its first run
    And I start a messenger consumer that stops after 1 delivery
    And I wait for the consumer to finish or timeout
    And I start a messenger consumer that stops after 1 delivery
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 2 times
    And the retries of the failing message should have waited at least "1000" ms, and less than 5 s more
    And the failure transport should contain 1 message

  # 10^13 ms is past the longest delay the client can send, 2^63 ns: the transport cuts it there, and the server holds
  # the message, still waiting for its acknowledgement when ack_wait has run out three times.
  @retry-via
  Scenario: A retry delay too long for the server is cut, and the server holds the message
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak&ack_wait=1", the retry strategy "{ max_retries: 2, delay: 10000000000000, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 2 deliveries or 4 seconds
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 1 time
    And the transport's consumer should have 1 delivery waiting for an acknowledgement
    And the NATS stream should hold 1 message
    And the failure transport should contain 0 messages

  @retry-via
  Scenario: A NAK retry that waits longer than stream_max_age loses its message
    Given I have a messenger transport configured with "stream_max_age=2&retry_via=nak", the retry strategy "{ max_retries: 1, delay: 4000, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 2 deliveries or 6 seconds
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 1 time
    And the NATS stream should hold 0 messages
    And the failure transport should contain 0 messages

  # NATS lengthens a NAK that carries a delay by backoff[n] - backoff[0], where n counts the earlier deliveries, which
  # is why the builder refuses backoff with retry_via nak. A consumer keeps a backoff another setup gave it.
  @retry-via
  Scenario: A consumer's backoff lengthens each NAK retry
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak", the retry strategy "{ max_retries: 3, delay: 500, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    And the transport's consumer has the backoff "1, 2 and 3" seconds, which the options do not say
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 4 times
    And the retries of the failing message should have waited at least "500, 1500 and 2500" ms, and less than 3 s more
    And the failure transport should contain 1 message

  # As nats_term.feature's scenario for retry_via copy. Symfony Messenger 8.1.11 and later fail the decoding failure
  # once, as unrecoverable, and the transport TERMs it; before 8.1 the transport TERMs it at once and the first worker
  # stops on the decode error.
  @retry-via
  Scenario: A message that cannot be decoded is settled with retry_via nak
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=nak", the retry strategy "{ max_retries: 2, delay: 100, multiplier: 2, jitter: 0 }" and a failure transport, both with the igbinary serializer
    And the NATS stream is set up
    And a message the transport cannot decode is published to its subject
    When two messenger consumers run one after the other for 3 seconds each
    Then the message that could not be decoded should have been settled
    And only a consumer before Symfony Messenger 8.1 should have stopped, on the decode error
