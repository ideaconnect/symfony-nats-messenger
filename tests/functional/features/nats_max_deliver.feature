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
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the always-failing message should have been attempted 3 times
