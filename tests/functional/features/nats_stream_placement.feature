Feature: NATS Stream Placement
  Tests the stream placement options (stream_placement_cluster, stream_placement_tags)
  that pin a stream to a JetStream cluster and to tagged servers. A standalone server
  stores a placement without acting on it, which is enough to verify what setup()
  writes: the configured placement on create, each configured option merged into its
  own field of an existing placement on update, the live tag list kept when the
  configured tags name the same set in another order, and the whole live placement
  echoed back when the options are unset (a STREAM.UPDATE that omits placement
  removes it). Verification queries the JetStream API for the stored placement.

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
    And the stream should have a max age of 15 minutes
    And the stream should have placement tags "hdd"

  @placement
  Scenario: Configured tags keep the cluster pin of an existing placement
    Given I have a messenger transport configured with stream placement tags "ssd"
    And the NATS stream already exists with placement cluster "east" and tags "hdd"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have placement cluster "east"
    And the stream should have placement tags "ssd"

  @placement
  Scenario: A configured cluster keeps the tags of an existing placement
    Given I have a messenger transport configured with stream placement cluster "west"
    And the NATS stream already exists with placement cluster "east" and tags "hdd"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have placement cluster "west"
    And the stream should have placement tags "hdd"

  @placement
  Scenario: The same tags in another order keep the stored tag list
    Given I have a messenger transport configured with stream placement tags "eu,ssd"
    And the NATS stream already exists with placement tags "ssd,eu"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have placement tags "ssd,eu"
