Feature: TLS NATS Transport
  In order to use NATS Messenger transport securely
  As a developer
  I need TLS connections to work

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
