Feature: Symfony failure transport via TERM
  In order to route permanently failed messages
  As a developer
  I need Symfony failure transport routing to work

  @term
  Scenario: Failed message routed to Symfony failure transport via TERM
    Given NATS server is running
    And I have a messenger transport with failure transport configured
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the failure transport should contain 1 message
