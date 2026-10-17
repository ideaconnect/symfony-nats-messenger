Feature: Automatic reconnect (reconnect: true)
  With reconnect on, the NATS client recovers a lost connection on its own and the transport leaves it to
  that: it never ends a recovery in flight, keeps a connection the client has already recovered, and verifies
  provisioning again once the client reconnected, since the stream may have gone with the server. The server is
  restarted with docker while a process holds a connection to it (#81).

  Background:
    Given NATS server is running
    And the test files directory is clean

  @reconnect
  Scenario: A worker keeps consuming across a server restart
    Given I have a messenger transport configured with reconnect enabled
    And the NATS stream is set up
    When I start a messenger consumer expecting 5 messages
    And I restart the NATS server
    And I send 5 messages to the transport
    And I wait for messages to be consumed
    Then all 5 messages should be consumed

  # The transport's ping_after_idle is 2 s here, so the send after the quiet period checks the connection first
  # and finds the client recovering it; without the check it would write into the dead socket and pay
  # request_timeout, since a PHP process notices a lost connection only inside a transport call.
  @reconnect
  Scenario: A message sent after a server restart goes out on the reconnected connection and the memory stream is recreated
    Given I have a messenger transport configured with reconnect enabled, auto_setup and memory storage
    And the NATS stream is set up
    When I send a message, restart the NATS server during a quiet period of 8 seconds, and send another from the same process
    Then the message sent after the quiet period should have gone out
    And the messenger stats should show exactly 1 messages waiting
