Feature: NKey authentication
  Tests a transport that authenticates with an NKey seed and no user or
  password. The NATS server knows the user only by its public key and
  checks a signature of its nonce. Verification: the stream is set up,
  and messages are sent and consumed over connections authenticated
  that way, the server refuses a seed it does not know, and credentials
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
  Scenario: A seed the server does not know is refused by the server
    Given I have a messenger transport configured with an NKey seed the server does not know
    When I run the messenger setup command
    Then the setup should fail with the server's authorization error

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
