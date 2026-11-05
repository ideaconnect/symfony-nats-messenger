Feature: NKey authentication
  Tests a transport that authenticates with an NKey seed and no user or
  password. The NATS server knows the user only by its public key and
  checks a signature of its nonce. Verification: the stream is set up,
  and messages are sent and consumed over connections authenticated
  that way, in every form the README shows, over TLS and across a server
  restart. The server refuses a seed it does not know, and credentials
  that need or exclude a seed fail before any connection, on every command
  that builds the transport.

  Background:
    Given NATS server is running

  @nkey
  Scenario: Messages are sent and consumed by a user that authenticates with an NKey seed
    Given I have a messenger transport configured with an NKey seed
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @nkey
  Scenario: A seed in the DSN query logs in as well
    Given I have a messenger transport with the NKey seed in the DSN query
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @nkey
  Scenario: An nkey option that repeats the seed's public key logs in
    Given I have a messenger transport configured with an NKey seed and its public key
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting

  @nkey @tls
  Scenario: A seed logs in over TLS
    Given NATS TLS server is running
    And I have a TLS messenger transport configured with an NKey seed
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @nkey @mtls
  Scenario: A seed logs in over mTLS, next to the client certificate
    Given NATS mTLS server is running
    And I have an mTLS messenger transport configured with an NKey seed
    And the NATS stream is set up
    And the test files directory is clean
    When I send 5 messages to the transport
    Then the messenger stats should show 5 messages waiting
    When I start a messenger consumer
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  @nkey @reconnect
  Scenario: A worker that logs in with a seed keeps consuming across a server restart
    Given the test files directory is clean
    And I have a messenger transport configured with an NKey seed and reconnect enabled
    And the NATS stream is set up
    When I start a messenger consumer expecting 5 messages
    And I restart the NATS server
    And I send 5 messages to the transport
    And I wait for messages to be consumed
    Then all 5 messages should be consumed
    And the consumer should have reconnected

  @nkey
  Scenario: A seed the server does not know is refused by the server
    Given I have a messenger transport configured with an NKey seed the server does not know
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error
    And the messenger stats should show exactly 0 messages waiting

  @nkey
  Scenario: A seed on a server without NKey users is refused by the client, as there is no nonce to sign
    Given the NATS server that logs clients in with a token is running
    And I have a messenger transport on that server with an NKey seed
    When I run the messenger setup command
    Then the setup should fail saying "NKey authentication requires server nonce from INFO"

  @nkey
  Scenario Outline: Credentials that need or exclude a seed are refused when the transport is built, on every command
    Given I have a messenger transport configured with <credentials>
    When I run the messenger setup command
    Then the setup should fail saying "<error>"
    And the messenger stats command should fail saying "<error>"
    And a messenger consumer should fail saying "<error>"

    Examples:
      | credentials                     | error                                        |
      | a JWT and no NKey seed          | The jwt option needs nkey_seed               |
      | a public NKey and no seed       | The nkey option needs nkey_seed              |
      | an NKey seed next to a password | The nkey_seed option cannot be combined with |

  @nkey
  Scenario Outline: A value that cannot log in is refused when the transport is built, and no seed is printed
    Given I have a messenger transport configured with <credentials>
    When I run the messenger setup command
    Then the setup should fail saying "<error>"
    And the setup output should show no seed

    Examples:
      | credentials                                         | error                                                       |
      | an empty NKey seed                                  | The nkey_seed option must be a non-empty string             |
      | a mistyped NKey seed                                | The NKey seed cannot be used                                |
      | the seed of an account as the NKey seed             | it is not a user seed                                       |
      | a seed in the nkey option next to the NKey seed     | The nkey option is not the public key of the configured     |
      | another user's public key next to the NKey seed     | The nkey option is not the public key of the configured     |
      | an NKey seed typed after a second question mark in the DSN | The NATS DSN holds a credential after a second        |
