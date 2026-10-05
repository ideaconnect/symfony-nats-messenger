Feature: Idle connection check (ping_after_idle)
  A server drops a client that stops answering its pings, which a PHP process does between two transport
  calls. This test server does so after about three seconds (ping_interval 1s, ping_max 1). With
  ping_after_idle, the transport checks a connection that sat idle longer than that with one PING before it
  uses it, and dials again when the server does not answer, so the message sent after the quiet period goes
  out instead of failing (5.2.0, #49).

  @ping-after-idle
  Scenario: A message sent after the server dropped the idle connection goes out
    Given the NATS server that drops idle clients is running
    And I have a messenger transport on that server with ping_after_idle of 1 second
    And the NATS stream is set up
    When I send a message, idle for 5 seconds, and send another from the same process
    Then the message sent after the quiet period should have gone out
    And the messenger stats should show exactly 2 messages waiting

  # The control: without the check, the send after the quiet period writes into the connection the server
  # closed, and fails. It shows that the server above really dropped the connection.
  @ping-after-idle
  Scenario: Without the check the message sent after the server dropped the connection fails
    Given the NATS server that drops idle clients is running
    And I have a messenger transport on that server with ping_after_idle of 0 seconds
    And the NATS stream is set up
    When I send a message, idle for 5 seconds, and send another from the same process
    Then the message sent after the quiet period should have failed
    And the messenger stats should show exactly 1 messages waiting
