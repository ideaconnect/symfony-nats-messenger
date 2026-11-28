Feature: Symfony failure transport via TERM
  Tests the Symfony retry handler path: when retry_handler=symfony (default),
  permanently failed messages are TERM'd and routed to the configured failure
  transport. Verification: messenger:stats confirms the failure transport
  received the expected number of messages.

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
    And the consumer should have warned once that Symfony's retry was published without its delay

  # The README's recommended IgbinarySerializer on both transports. A worker adds a stamp holding a closure
  # to every message it handles; the serializer used to serialize it with the retry and the send to the
  # failure transport, which failed and stopped the consumer before the message reached either (#46).
  @term
  Scenario: Failed message routed to Symfony failure transport via TERM with the igbinary serializer
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "IDCT\NatsMessenger\Serializer\IgbinarySerializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    When I send 1 always-failing message
    And I start a messenger consumer with high limit
    And I wait for the consumer to finish or timeout
    Then the failure transport should contain 1 message

  # A message the igbinary serializer cannot decode, in symfony mode with a failure transport, handled as Symfony's own
  # transports handle it. Before Symfony Messenger 8.1 the transport TERMs it and throws the decode error, which stops
  # the worker that pulled it. From 8.1 on the worker fails it as the decoding failure and Symfony's retry strategy and
  # failure transport deal with it: 8.1.11 and later fail it once, as unrecoverable, and 8.1.0 to 8.1.10 retry it.
  @term
  Scenario: A message that cannot be decoded is settled in symfony mode
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "IDCT\NatsMessenger\Serializer\IgbinarySerializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    And a message the transport cannot decode is published to its subject
    When two messenger consumers run one after the other for 3 seconds each
    Then the message that could not be decoded should have been settled
    And only a consumer before Symfony Messenger 8.1 should have stopped, on the decode error

  # A message whose __wakeup() raises a warning it suppresses, as legacy code looking for a cache file does, through the
  # igbinary serializer, in symfony mode with a failure transport. The warning is the application's: the message is
  # handled and acknowledged. 5.10.0 made it a decode failure: the handler never ran, the message was TERMed and a copy
  # went to the failure transport (#122).
  @term
  Scenario: A message whose __wakeup raises a suppressed warning is handled and acknowledged in symfony mode
    Given NATS server is running
    And I have a messenger transport with failure transport configured using the "IDCT\NatsMessenger\Serializer\IgbinarySerializer" serializer
    And the NATS stream is set up
    And the failure stream is set up
    And the test files directory is clean
    And I listen for the TERMs of the transport's consumer
    When I send 1 message whose __wakeup raises a suppressed warning
    And I start a messenger consumer
    And I wait for messages to be consumed
    Then the message whose __wakeup raised a warning should have been handled once
    And its one delivery should have been acknowledged, not TERMed
    And the failure transport should contain 0 messages

  # A serializer of the application's own whose decode() throws something other than a MessageDecodingFailedException,
  # as a schema registry or another service it depends on being away would. Nothing settles the message: the worker
  # stops with the exception, and NATS redelivers the message after ack_wait to whichever worker pulls next, which
  # stops too, for ever without max_deliver. The same in either retry mode, since the exception never reaches
  # Symfony's retry strategy (#126).
  @term
  Scenario Outline: An exception of the serializer other than a decoding failure leaves the message to come back every ack_wait, and stops a worker each time
    Given NATS server is running
    And I have a messenger transport configured with retry handler <handler>, an ack wait of 1 second and a serializer whose decode always fails
    And the NATS stream is set up
    When I send 1 messages to the transport
    And two messenger consumers run one after the other for 3 seconds each
    Then both consumers should have stopped on the serializer's exception
    And the message the serializer could not decode should have been delivered twice, with 1 redelivery and 1 pending acknowledgement
    And the NATS stream should hold 1 message

    Examples:
      | handler |
      | symfony |
      | nats    |

  # max_deliver 1, not 2: with two consumers and max_deliver 2 both would still stop on the exception, since the
  # second delivery is only the consumer's second attempt, not past max_deliver. The MAX_DELIVERIES advisory itself
  # waits for the next pull once max_deliver is reached, which the second consumer's own pull provides here.
  # num_redelivered of that delivery is not asserted: 0 on NATS 2.15.3, 1 on 2.10.29.
  @term
  Scenario: An exception of the serializer other than a decoding failure stops redelivery at max_deliver
    Given NATS server is running
    And I have a messenger transport configured with retry handler symfony, an ack wait of 1 second, max deliver of 1 and a serializer whose decode always fails
    And the NATS stream is set up
    And I listen for the advisories of the transport's consumer
    When I send 1 messages to the transport
    And two messenger consumers run one after the other for 3 seconds each
    Then the first consumer should have stopped on the serializer's exception, and the second should have run to its time limit
    And the transport's consumer should have published "MAX_DELIVERIES" for stream sequence 1
    And the NATS stream should hold 1 message
