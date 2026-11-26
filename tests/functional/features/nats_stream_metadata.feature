Feature: NATS Stream Metadata
  Tests the stream_metadata option, which sets entries in the stream's metadata: the configured
  entries on create, and on an existing stream each configured entry set in the live map, or
  removed from it when its value is null, where every other entry stays, the ones NATS writes
  itself included. With the option unset the live
  map goes back as it is (a STREAM.UPDATE that omits metadata removes it). A name NATS keeps for
  itself is refused. Verification queries the JetStream API for the stored metadata. Stream
  metadata needs NATS 2.10, the oldest server CI runs, so the feature is not tagged @delayed and
  the oldest-server job runs it too.

  Background:
    Given NATS server is running

  @metadata
  Scenario: Setup creates the stream with the metadata supplied via the DSN
    Given I have a messenger transport configured with stream metadata "group=odds-papi,shard=7"
    When I run the messenger setup command
    Then the NATS stream should be created successfully
    And the stream should have the metadata "group=odds-papi,shard=7"

  @metadata
  Scenario: Setup sets the configured entries and keeps the others of an existing stream
    Given I have a messenger transport configured with stream metadata "group=odds-v2,team=core"
    And the NATS stream already exists with metadata "group=odds-papi,owner=devops"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have the metadata "group=odds-v2,owner=devops,team=core"
    And the metadata NATS writes itself should be as it was

  @metadata
  Scenario: Setup keeps the metadata of an existing stream when the option is unset
    Given I have a messenger transport configured with max age of 15 minutes
    And the NATS stream already exists with metadata "group=odds-papi,owner=devops"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have the metadata "group=odds-papi,owner=devops"
    And the metadata NATS writes itself should be as it was

  @metadata
  Scenario: Setup removes an entry given null and keeps the others of an existing stream
    Given I have a messenger transport configured to set the stream metadata "team=core" and to remove "owner"
    And the NATS stream already exists with metadata "group=odds-papi,owner=devops"
    When I run the messenger setup command
    Then the setup should complete successfully
    And the stream should have a max age of 15 minutes
    And the stream should have the metadata "group=odds-papi,team=core"
    And the metadata NATS writes itself should be as it was

  @metadata
  Scenario: A metadata name NATS keeps for itself is refused
    Given I have a messenger transport configured with stream metadata "group=odds-papi,_nats.ver=9.9.9"
    When I run the messenger setup command
    Then the setup should fail saying 'The stream_metadata option cannot set "_nats.ver": NATS keeps the names starting with "_nats." for itself.'
