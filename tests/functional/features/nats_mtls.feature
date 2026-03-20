Feature: mTLS NATS Transport
  Tests mutual TLS (mTLS) connections where both server and client present
  certificates. Uses the mTLS NATS container on port 4224 with CA, client
  cert, and client key files. Verification: messages are sent and consumed
  successfully, proving client certificate authentication works.

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
