Feature: Token authentication
  Tests a transport that logs in with a token, on a NATS server whose clients
  all share one. Verification: the stream is set up, and messages are sent and
  consumed over connections logged in that way, and the server refuses a wrong
  token.

  Background:
    Given the NATS server that logs clients in with a token is running

  @token
  Scenario: Messages are sent and consumed by a client that logs in with a token
    Given I have a messenger transport on that server with its token
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @token
  Scenario: A wrong token is refused by the server
    Given I have a messenger transport on that server with a wrong token
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error
