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

  # The README's recommended IgbinarySerializer on both transports. A worker adds a stamp holding a closure
  # to every message it handles; the serializer used to serialize it with the retry and the send to the
  # failure transport, which failed and stopped the consumer before the message reached either (#46).
  @term
  Scenario: Failed message routed to Symfony failure transport via TERM with the igbinary serializer
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "igbinary_serializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the failure transport should contain 1 message
