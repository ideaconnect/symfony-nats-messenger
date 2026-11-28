Feature: NATS-native redelivery cap (max_deliver)
  Tests that retry_handler=nats combined with max_deliver bounds redelivery: a
  permanently-failing ("poison") message is redelivered up to max_deliver times and
  then NATS stops, instead of redelivering it forever. The failing handler records
  every delivery attempt so the count can be asserted.

  Background:
    Given NATS server is running

  @max-deliver
  Scenario: NATS stops redelivering a poison message after max_deliver attempts
    Given I have a messenger transport configured with NATS retry handler and max deliver of 3
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    And I listen for the advisories of the transport's consumer
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the consumer should not have warned that Symfony's retry strategy is on
    And the transport's consumer should have published "MSG_NAKED, MSG_NAKED, MSG_NAKED, MAX_DELIVERIES" for stream sequence 1
    And the NATS stream should hold 1 message

  # Symfony's retry strategy left at its framework default (3 retries). In nats mode the transport does not
  # publish the copies Symfony's retry sends: NATS redelivers alone, so max_deliver still bounds the attempts.
  # Published as well, the copies multiplied the attempts (one message ran its handler 120 times, #47).
  # The first copy left out makes the transport warn, once per consumer, that max_retries should be 0 (#94).
  @max-deliver
  Scenario: Symfony's retry strategy is ignored with the NATS retry handler
    Given I have a messenger transport configured with NATS retry handler, max deliver of 3 and Symfony's default retry strategy
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
    And the consumer should have warned once that Symfony's retry strategy is on

  @max-deliver
  Scenario: In nats mode, setup warns about an interest stream when the server deletes a message stopped at max_deliver
    Given I have a messenger transport configured with the NATS retry handler, max_deliver 3 and interest retention
    When I run the messenger setup command with its warnings shown
    Then the NATS stream should be created successfully
    And the setup should have warned that NATS deletes a message stopped at max_deliver exactly when the server's version does

  # What the stream holds of a message stopped at max_deliver depends on its retention, its replicas and the server
  # (docs/symfony_retry_via.md): on one server, once later messages were acknowledged and the server restarted, a
  # work-queue stream keeps it, and an interest stream deletes it before 2.12.9 and on 2.14.0. The step reads the
  # server's version. A limits stream always keeps it, as the first scenario of this feature shows.
  @max-deliver
  Scenario Outline: A message stopped at max_deliver on a <retention> stream, across a restart of the server
    Given I have a messenger transport configured with "stream_max_age=900&retry_handler=nats&max_deliver=2&stream_retention=<retention>" and the retry strategy "{ max_retries: 0 }"
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send 1 always-failing message
    And I send 2 messages to the transport
    And I start a messenger consumer that stops after 4 deliveries
    And I wait for the consumer to finish or timeout
    And I restart the NATS server
    Then the always-failing message should have been attempted 2 times
    And the test files directory should contain 2 files
    And the NATS stream should hold the message stopped at max_deliver exactly when the server keeps it on a <retention> stream

    Examples:
      | retention |
      | workqueue |
      | interest  |
