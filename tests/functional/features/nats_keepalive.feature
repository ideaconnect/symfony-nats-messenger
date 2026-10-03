Feature: Keepalive for long-running handlers (messenger:consume --keepalive)
  Tests that `messenger:consume --keepalive` keeps NATS from redelivering a message to another
  consumer while its handler is still working on it past the consumer's ack_wait. Symfony sends the
  keepalive from a SIGALRM handler, where the transport queues the in-progress acknowledgement instead
  of waiting for it: waiting there stopped the worker, or failed the message being handled, at the
  first alarm (#48). The queued acknowledgement goes out the next time the handler waits on the event
  loop, which the slow handler here does every 100 ms.

  Background:
    Given NATS server is running

  @keepalive
  Scenario: A message handled for longer than ack_wait is not redelivered while keepalive runs
    Given I have a messenger transport configured with an ack wait of 3 seconds
    And the NATS stream is set up
    And the retry state directory is clean
    And the test files directory is clean
    When I send a slow message that takes 7 seconds to handle
    And I start a messenger consumer with a keepalive every 1 second
    And I wait until the slow message is being handled
    And I start another messenger consumer for 8 seconds
    And I wait for the consumers to finish
    Then the slow message should have been handled 1 time
