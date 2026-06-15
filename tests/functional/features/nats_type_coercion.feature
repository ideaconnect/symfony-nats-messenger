Feature: Type coercion of DSN options
  Numeric transport options supplied via the DSN query string arrive as PHP strings
  (parse_str), so IDCT\NatsMessenger\TypeCoercion must coerce them to numbers before
  they are applied. This verifies that string→int coercion works end-to-end against a
  real JetStream stream, not just in isolation.

  Background:
    Given NATS server is running

  @coercion
  Scenario: Numeric options supplied via the DSN query string are coerced and applied
    Given I have a messenger transport configured with numeric stream limits supplied via the DSN query string
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have a max age of 15 minutes
    And the stream should have max bytes of 1048576
    And the stream should have max messages of 100
