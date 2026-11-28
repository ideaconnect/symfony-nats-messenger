Feature: Symfony's retries through NAKs when the consumer info cannot be read (retry_via: nak)
  With retry_via=nak the transport reads the consumer's own max_deliver at the first retry that needs it, since the
  retry of the last delivery max_deliver allows has to go out as a copy: NATS redelivers nothing after that delivery.
  When the read gets no answer within request_timeout, or the server refuses it because the user may not ask for
  consumer info, the max_deliver option decides that retry, with one warning, and the worker carries on. Up to 5.10.1
  a read that got no answer made the send of the retry fail and stopped the worker, and the message was lost with
  no failure-transport copy (#127).

  Background:
    Given NATS server is running
    And I have a messenger transport configured for the consumer info reads with NAK retries, max_deliver 2, max_retries 3, request_timeout 1, ack_wait 2 and a failure transport
    And the NATS stream is set up
    And the failure stream is set up
    And the retry state directory is clean
    And the test files directory is clean

  @retry-via
  Scenario: Consumer info requests that go unanswered leave the max_deliver option to decide, and the worker carries on
    Given the transport's consumer info requests go unanswered
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    Then the consumer should have run to its limit, not stopped by a consumer info read
    And the always-failing message should have been attempted 4 times
    And the failure transport should contain 1 message
    And the NATS stream should hold 2 messages
    And the transport's consumer info requests should have gone unanswered
    And the consumer should have warned once that the consumer info could not be read, so the max_deliver option decided

  @retry-via
  Scenario: A user who may not ask for consumer info leaves the max_deliver option to decide, and the worker carries on
    Given the transport connects as a user who may not ask for consumer info
    When I send 1 always-failing message
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    Then the consumer should have run to its limit, not stopped by a consumer info read
    And the always-failing message should have been attempted 4 times
    And the failure transport should contain 1 message
    And the NATS stream should hold 2 messages
    And the consumer should have warned once that the user may not ask for consumer info, so the max_deliver option decided
