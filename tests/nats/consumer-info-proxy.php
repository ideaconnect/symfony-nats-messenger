<?php

/**
 * A TCP proxy in front of a NATS server that leaves every consumer info request unanswered, for the scenarios and the
 * example of a consumer that cannot be read with retry_via: nak (#127): the Behat steps in NatsSetupContext and
 * examples/retry-via-nak-consumer-info.php start it.
 *
 * It forwards both ways byte for byte, and drops only a client's PUB or HPUB whose subject starts with
 * `$JS.API.CONSUMER.INFO.`: it takes the payload's size from the line and skips that many bytes and the CRLF after
 * them. The server never sees the request, so the client's request runs into its request_timeout, as with a server
 * that is too slow to answer. Pulls, publishes, acknowledgements and every other request go through.
 *
 * Usage: php tests/nats/consumer-info-proxy.php HOST:PORT [SECONDS]
 *
 * It listens on a free port of 127.0.0.1 and prints "LISTENING <port>" once it does, then "DROPPED <subject>" for each
 * request it drops. It runs until it is stopped (SIGTERM), or for SECONDS (300 by default), so that one its starter
 * failed to stop does not outlive the test run for long. Needs no Composer package.
 */

declare(strict_types=1);

const DROPPED_PREFIX = '$JS.API.CONSUMER.INFO.';

if ($argc < 2 || !preg_match('/^[^:]+:\d+$/', $argv[1])) {
    fwrite(STDERR, "Usage: php consumer-info-proxy.php HOST:PORT [SECONDS]\n");
    exit(2);
}

$upstreamAddress = 'tcp://' . $argv[1];
$stopAt = microtime(true) + (float) ($argv[2] ?? 300);

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listener === false) {
    fwrite(STDERR, sprintf("Cannot listen: %s (%d)\n", $error, $errno));
    exit(1);
}

$name = (string) stream_socket_get_name($listener, false);
echo 'LISTENING ', substr($name, (int) strrpos($name, ':') + 1), "\n";
flush();

/**
 * One client connection and its connection to the server, with where the parser stands in the client's bytes.
 *
 * @var array<int, array{client: resource, server: resource, line: string, skip: int, pass: int}> $pairs
 */
$pairs = [];

/** Writes all of $bytes, or returns false once the peer is gone. */
function writeAll($stream, string $bytes): bool
{
    while ($bytes !== '') {
        $written = @fwrite($stream, $bytes);
        if ($written === false || $written === 0) {
            return false;
        }
        $bytes = substr($bytes, $written);
    }

    return true;
}

/**
 * What of the client's bytes goes on to the server: every protocol line but a consumer info request, with the
 * payload of each PUB and HPUB that goes on, and none of the payload of the ones dropped.
 *
 * @param array{client: resource, server: resource, line: string, skip: int, pass: int} $pair
 */
function forwardedOf(array &$pair, string $bytes): string
{
    $buffer = $pair['line'] . $bytes;
    $pair['line'] = '';
    $length = strlen($buffer);
    $position = 0;
    $forwarded = '';

    while ($position < $length) {
        if ($pair['skip'] > 0) {
            $taken = min($pair['skip'], $length - $position);
            $pair['skip'] -= $taken;
            $position += $taken;
            continue;
        }

        if ($pair['pass'] > 0) {
            $taken = min($pair['pass'], $length - $position);
            $forwarded .= substr($buffer, $position, $taken);
            $pair['pass'] -= $taken;
            $position += $taken;
            continue;
        }

        $end = strpos($buffer, "\r\n", $position);
        if ($end === false) {
            // Half a protocol line: the rest comes with the next read.
            $pair['line'] = substr($buffer, $position);
            break;
        }

        $line = substr($buffer, $position, $end - $position);
        $position = $end + 2;
        $fields = preg_split('/[ \t]+/', trim($line)) ?: [];
        $operation = strtoupper($fields[0] ?? '');

        if (($operation === 'PUB' || $operation === 'HPUB') && count($fields) >= 3) {
            // PUB <subject> [reply-to] <size>, HPUB <subject> [reply-to] <header size> <total size>: the last field is
            // the size of what follows the line, before its own CRLF.
            $payload = (int) end($fields) + 2;
            if (str_starts_with($fields[1], DROPPED_PREFIX)) {
                $pair['skip'] = $payload;
                echo 'DROPPED ', $fields[1], "\n";
                flush();
                continue;
            }

            $forwarded .= $line . "\r\n";
            $pair['pass'] = $payload;
            continue;
        }

        $forwarded .= $line . "\r\n";
    }

    return $forwarded;
}

function closePair(array &$pairs, int $id): void
{
    @fclose($pairs[$id]['client']);
    @fclose($pairs[$id]['server']);
    unset($pairs[$id]);
}

while (microtime(true) < $stopAt) {
    $read = [$listener];
    foreach ($pairs as $pair) {
        $read[] = $pair['client'];
        $read[] = $pair['server'];
    }
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $listener) {
            $client = @stream_socket_accept($listener, 0);
            if ($client === false) {
                continue;
            }
            $server = @stream_socket_client($upstreamAddress, $errno, $error, 5);
            if ($server === false) {
                fwrite(STDERR, sprintf("Cannot reach %s: %s (%d)\n", $upstreamAddress, $error, $errno));
                fclose($client);
                continue;
            }
            stream_set_read_buffer($client, 0);
            stream_set_read_buffer($server, 0);
            $pairs[(int) $client] = ['client' => $client, 'server' => $server, 'line' => '', 'skip' => 0, 'pass' => 0];
            continue;
        }

        foreach ($pairs as $id => $pair) {
            if ($stream !== $pair['client'] && $stream !== $pair['server']) {
                continue;
            }

            $bytes = @fread($stream, 65536);
            if ($bytes === false || ($bytes === '' && feof($stream))) {
                closePair($pairs, $id);
                break;
            }

            $sent = $stream === $pair['client']
                ? writeAll($pair['server'], forwardedOf($pairs[$id], $bytes))
                : writeAll($pair['client'], $bytes);
            if (!$sent) {
                closePair($pairs, $id);
            }
            break;
        }
    }
}
