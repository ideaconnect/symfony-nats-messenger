Feature: NATS Stream Placement
  Tests the stream placement option (stream_placement_tags) that pins a stream to
  tagged JetStream servers. A standalone server stores a placement without acting
  on it, which is enough to verify that setup() writes the configured placement
  and, when the option is unset, echoes an existing placement back instead of
  letting the update clear it (a STREAM.UPDATE that omits placement removes it).
  Verification queries the JetStream API for the stored placement.

  Background:
    Given NATS server is running

  @placement
  Scenario: Setup stream with placement tags supplied via the DSN
    Given I have a messenger transport configured with stream placement tags "ssd,eu-west"
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have placement tags "ssd,eu-west"

  @placement
  Scenario: Update of an existing stream applies the configured placement tags
    Given I have a messenger transport configured with stream placement tags "ssd,eu-west"
    And the NATS stream already exists
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have placement tags "ssd,eu-west"

  @placement
  Scenario: Update of an existing stream keeps its placement when the option is unset
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream already exists with placement tags "hdd"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have placement tags "hdd"
