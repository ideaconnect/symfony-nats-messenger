Feature: Symfony failure transport via TERM
  Tests the Symfony retry handler path: when retry_handler=symfony (default),
  permanently failed messages are TERM'd and routed to the configured failure
  transport. Verification: messenger:stats confirms the failure transport
  received the expected number of messages.

  @term
  Scenario: Failed message routed to Symfony failure transport via TERM
    Given NATS server is running
    And I have a messenger transport with failure transport configured
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the failure transport should contain 1 message
    And the consumer should have warned once that Symfony's retry was published without its delay

  # The README's recommended IgbinarySerializer on both transports. A worker adds a stamp holding a closure
  # to every message it handles; the serializer used to serialize it with the retry and the send to the
  # failure transport, which failed and stopped the consumer before the message reached either (#46).
  @term
  Scenario: Failed message routed to Symfony failure transport via TERM with the igbinary serializer
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "IDCT\NatsMessenger\Serializer\IgbinarySerializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the failure transport should contain 1 message

  # A message the igbinary serializer cannot decode, in symfony mode with a failure transport. Before Symfony Messenger
  # 8.1 the transport TERMs it when it is received and logs it, and up to 5.9.x it rethrew the decode error, which
  # stopped the worker. From 8.1 on the worker fails it as the decoding failure and Symfony's retry strategy and
  # failure transport deal with it: 8.1.11 and later fail it once, as unrecoverable, and 8.1.0 to 8.1.10 retry it.
  @term
  Scenario: A message that cannot be decoded does not stop the worker in symfony mode
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "IDCT\NatsMessenger\Serializer\IgbinarySerializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    And a message the transport cannot decode is published to its subject
    When two messenger consumers run one after the other for 3 seconds each
    Then the message that could not be decoded should have been settled
    And neither messenger consumer should have stopped on an error
