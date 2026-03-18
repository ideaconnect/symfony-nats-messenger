Feature: mTLS NATS Transport
  In order to use NATS Messenger transport with mutual TLS
  As a developer
  I need mTLS connections with client certificates to work

  @mtls
  Scenario: mTLS server connection with client certificate
    Given NATS mTLS server is running
    And I have an mTLS messenger transport configured
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed
