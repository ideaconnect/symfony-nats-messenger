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
