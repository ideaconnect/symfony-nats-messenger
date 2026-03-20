Feature: TLS NATS Transport
  Tests one-way TLS connections using the nats-jetstream+tls:// DSN scheme.
  Covers both native PHP serializer and igbinary serializer to ensure
  TLS does not interfere with serialization. Verification: messages are
  sent and consumed successfully over the encrypted connection.

  @tls
  Scenario: TLS server connection with native PHP serializer
    Given NATS TLS server is running
    And I have a TLS messenger transport configured using "messenger.transport.native_php_serializer"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @tls
  Scenario: TLS server connection with igbinary serializer
    Given NATS TLS server is running
    And I have a TLS messenger transport configured using "igbinary_serializer"
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed
