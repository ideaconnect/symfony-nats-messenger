Feature: A worker whose user may not pull
  Tests a worker whose NATS user may not publish to the consumer's
  $JS.API.CONSUMER.MSG.NEXT.<stream>.<consumer>, the subject of a pull. The
  server refuses each pull with a permissions violation and keeps the
  connection open. The worker used to read each refusal as an empty pull and
  run on without an error while the messages waited (#130). Verification: the
  worker exits nonzero with the error naming the subject its user needs to
  publish to, and a worker whose user may pull then consumes the waiting
  message.

  Background:
    Given NATS server is running

  @permissions
  Scenario: A worker whose user may not pull stops with the permission it needs
    Given I have a messenger transport configured with consumer name "worker"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 1 messages to the transport
    And the transport's user may not pull from the consumer "worker"
    Then a messenger consumer should fail naming the permission to publish to "$JS.API.CONSUMER.MSG.NEXT.stream.worker"
    And the messenger stats should show 1 messages waiting
    When I have a messenger transport configured with consumer name "worker"
    And I start a messenger consumer
    And I wait for messages to be consumed
    Then all 1 messages should be consumed
