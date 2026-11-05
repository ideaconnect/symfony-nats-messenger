Feature: JWT authentication
  Tests a transport that logs in with a user's JWT and seed, on a NATS server
  in operator mode, which has no list of users. It trusts an operator, and
  with it the accounts the operator signed. Verification: the stream is set
  up, and messages are sent and consumed over connections logged in that way,
  with the values read from the environment too, with a bearer JWT and across
  a server restart. The server refuses a JWT with a seed that is not its
  user's, a seed with no JWT and an expired JWT, and an account without
  JetStream gets the server's own error from setup.

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
  Scenario: A JWT and its seed read from the environment log in, in the form the README's configuration shows
    Given I have a messenger transport on that server with the user's JWT and seed read from the environment
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @jwt
  Scenario: A bearer JWT logs in with its seed
    Given I have a messenger transport on that server with a bearer JWT and its seed
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @jwt @reconnect
  Scenario: A worker that logs in with a JWT keeps consuming across a server restart
    Given the test files directory is clean
    And I have a messenger transport on that server with the user's JWT and seed and reconnect enabled
    And the NATS stream is set up
    When I start a messenger consumer expecting 5 messages
    And I restart the NATS server
    And I send 5 messages to the transport
    And I wait for messages to be consumed
    Then all 5 messages should be consumed
    And the consumer should have reconnected

  @jwt
  Scenario: A JWT with another user's seed is refused by the server
    Given I have a messenger transport on that server with the user's JWT and another user's seed
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error
    And the messenger stats should show exactly 0 messages waiting

  @jwt
  Scenario: A seed with no JWT is refused by the server
    Given I have a messenger transport on that server with the user's seed and no JWT
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error

  @jwt
  Scenario: An expired JWT is refused by the server
    Given I have a messenger transport on that server with an expired JWT and its seed
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error

  @jwt
  Scenario Outline: A value that is not a JWT is refused when the transport is built, and no seed is printed
    Given I have a messenger transport on that server with <value> in the jwt option
    When I run the messenger setup command
    Then the setup should fail saying "The jwt option is not a JWT"
    And the setup output should show no seed

    Examples:
      | value                     |
      | the seed                  |
      | the text of a .creds file |

  @jwt
  Scenario: A user of an account without JetStream gets the server's error from setup
    Given I have a messenger transport on that server with the JWT and seed of an account without JetStream
    When I run the messenger setup command
    Then the setup should fail saying "JetStream not enabled for account"
