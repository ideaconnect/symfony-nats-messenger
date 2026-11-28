Feature: Symfony's retries published as copies (retry_via: copy)
  With retry_handler=symfony and retry_via=copy, the defaults, Symfony's retry strategy decides whether and when a
  failed message is retried, and its retry goes to NATS as a copy: a new message on the same subject, delivered at
  once unless scheduled_messages makes it wait. The worker settles the failed delivery once the copy is out, with an
  ACK where Symfony Messenger acknowledges a message it sent for retry (7.4.22, 8.1.10 and later) and a TERM where it
  rejects it, so the scenarios assert the runs, the copies, what the stream and the failure transport hold, and only
  the advisories every release publishes. The handler records each run, and when it started.

  Background:
    Given NATS server is running
    And the retry state directory is clean
    And the test files directory is clean

  @retry-copy
  Scenario: A message that fails once is handled on its retry copy
    Given I have a messenger transport configured with "stream_max_age=900", the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 retryable failing message
    And I start a messenger consumer that stops after 2 deliveries
    And I wait for the consumer to finish or timeout
    Then the retryable message should have been processed successfully
    And the failure transport should contain 0 messages
    And the NATS stream should hold 2 messages
    And the consumer should have warned once that Symfony's retry was published without its delay

  # Symfony gives up without a failure transport by rejecting the message, which symfony mode TERMs. A TERM leaves the
  # message on a limits stream, with every retry copy before it, and removes it from a work-queue or interest stream
  # as an acknowledgement does (docs/symfony_retry_via.md). With nak the one message is the only one, NAKed and then
  # TERMed.
  @retry-copy
  Scenario Outline: A message given up on without a failure transport stays on a limits stream and leaves a <retention> one, with retry_via <via>
    Given I have a messenger transport configured with "stream_max_age=900&retry_via=<via>&stream_retention=<retention>" and the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }"
    And the NATS stream is set up
    And I listen for the advisories of the transport's consumer
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 2 deliveries
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 2 times
    And the transport's consumer should have published "<advisories>" for stream sequence <last sequence>, whatever it published for the others
    And the NATS stream should hold <stored> messages

    Examples:
      | via  | retention | last sequence | advisories                | stored |
      | copy | limits    | 2             | MSG_TERMINATED            | 2      |
      | copy | workqueue | 2             | MSG_TERMINATED            | 0      |
      | copy | interest  | 2             | MSG_TERMINATED            | 0      |
      | nak  | limits    | 1             | MSG_NAKED, MSG_TERMINATED | 1      |
      | nak  | workqueue | 1             | MSG_NAKED, MSG_TERMINATED | 0      |
      | nak  | interest  | 1             | MSG_NAKED, MSG_TERMINATED | 0      |

  @retry-copy
  Scenario: An unrecoverable failure runs once and leaves one failure copy
    Given I have a messenger transport configured with "stream_max_age=900", the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    And I listen for the advisories of the transport's consumer
    When I send 1 unrecoverable failing message
    And I start a messenger consumer that stops after 1 delivery
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 1 time
    And the failure transport should contain 1 message
    And the transport's consumer should have published "MSG_TERMINATED" for stream sequence 1
    And the NATS stream should hold 1 message

  # Symfony retries a RecoverableExceptionInterface whatever max_retries says, so each failure publishes one more copy.
  @retry-copy
  Scenario: A recoverable failure is retried through copies past max_retries until it is handled
    Given I have a messenger transport configured with "stream_max_age=900", the retry strategy "{ max_retries: 1, delay: 100, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 message that fails with a RecoverableMessageHandlingException on its first 3 runs
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    Then the failing message should have been handled on its run 4
    And the failure transport should contain 0 messages
    And the NATS stream should hold 4 messages

  # With scheduled_messages the copy goes to a delayed subject with Nats-Schedule headers, and NATS delivers it at the
  # next whole second after its delay; without it the delay is dropped and the copy runs at once. Needs NATS 2.12.
  @retry-copy @delayed
  Scenario: With scheduled_messages a retry copy waits for its delay
    Given I have a messenger transport configured with "stream_max_age=900&scheduled_messages=true", the retry strategy "{ max_retries: 1, delay: 500, multiplier: 1, jitter: 0 }" and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 retryable failing message
    And I start a messenger consumer that stops after 2 deliveries
    And I wait for the consumer to finish or timeout
    Then the retryable message should have been processed successfully
    And the retries of the failing message should have waited at least "500" ms, and less than 5 s more
    And the failure transport should contain 0 messages
    And the consumer should not have warned that Symfony's retry was published without its delay
