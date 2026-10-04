Feature: Duplicate protection (deduplication ids)
  Tests that JetStream drops a message sent again with the same deduplication id within the stream's
  duplicate window. A message dispatched again after a send() that timed out, although the server had
  stored it, is the case this guards against (#53); here the application dispatches a message a second
  time with its own id. With the deduplicate option every message gets an id of its own, so distinct
  messages are all stored.

  Background:
    Given NATS server is running

  @deduplicate
  Scenario: A message dispatched again with the same deduplication id is stored once
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream is set up
    And the test files directory is clean
    When I send 3 messages with deduplication ids, and then the first one again
    Then the messenger stats should show exactly 3 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 3 messages should be consumed

  @deduplicate
  Scenario: Distinct messages are all stored with the deduplicate option
    Given I have a messenger transport configured with deduplication
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show exactly 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  # Symfony hands each transport a message is routed to the envelope the one before returned, deduplication
  # id included. JetStream deduplicates per stream whatever the subject, so in 5.4.0, which added the
  # option, the second transport's copy was dropped. The id now holds the transport's subject too.
  @deduplicate
  Scenario: A message routed to two deduplicating transports on one stream is stored for each
    Given I have two deduplicating messenger transports sharing the same stream with subjects "orders.created" and "payments.created", the test message routed to both
    When I run the messenger setup command for both shared transports
    Then the setup should complete successfully
    When I send 1 messages to the transport
    Then the NATS stream should hold exactly 2 messages
