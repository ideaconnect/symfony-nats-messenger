Feature: JWT authentication
  Tests a transport that logs in with a user's JWT and seed, on a NATS server
  in operator mode, which has no list of users. It trusts an operator, and
  with it the accounts the operator signed. Verification: the stream is set
  up, and messages are sent and consumed over connections logged in that way,
  and the server refuses the JWT with a seed that is not its user's.

  Background:
    Given the NATS server in operator mode is running

  @jwt
  Scenario: Messages are sent and consumed by a user that logs in with a JWT and its seed
    Given I have a messenger transport on that server with the user's JWT and seed
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @jwt
  Scenario: A JWT with another user's seed is refused by the server
    Given I have a messenger transport on that server with the user's JWT and another user's seed
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error
