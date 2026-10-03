<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Options;

/**
 * Enum of all recognized transport configuration option keys.
 *
 * Each case maps to a string key used in DSN query parameters, YAML transport config,
 * and the internal merged options array. Grouped by function:
 *
 * **Consumer & Batching:** CONSUMER, BATCHING, MAX_BATCH_TIMEOUT, CONNECTION_TIMEOUT,
 *                          MAX_ACK_PENDING, INACTIVE_THRESHOLD, REPLAY_POLICY
 * **Connection:** PING_AFTER_IDLE, REQUEST_TIMEOUT
 * **Stream Limits:** STREAM_MAX_AGE, STREAM_MAX_BYTES, STREAM_MAX_MESSAGES,
 *                    STREAM_MAX_MESSAGES_PER_SUBJECT, STREAM_MAX_MESSAGE_SIZE, STREAM_MAX_CONSUMERS,
 *                    STREAM_STORAGE, STREAM_REPLICAS
 * **Stream Policy:** STREAM_RETENTION, STREAM_DISCARD, STREAM_DUPLICATE_WINDOW, STREAM_COMPRESSION,
 *                    STREAM_DESCRIPTION, STREAM_DENY_DELETE, STREAM_DENY_PURGE, STREAM_ALLOW_DIRECT,
 *                    STREAM_ALLOW_ROLLUP_HEADERS
 * **Retry Strategy:** RETRY_HANDLER, NAK_DELAY, ACK_WAIT, MAX_DELIVER, BACKOFF
 * **Acknowledgement:** ACK_SYNC
 * **Deduplication:** DEDUPLICATE
 * **Scheduling:** SCHEDULED_MESSAGES
 * **Provisioning:** AUTO_SETUP
 * **TLS:** TLS_REQUIRED, TLS_HANDSHAKE_FIRST, TLS_CA_FILE, TLS_CERT_FILE, TLS_KEY_FILE,
 *          TLS_KEY_PASSPHRASE, TLS_PEER_NAME, TLS_VERIFY_PEER
 * **Authentication:** TOKEN, JWT, NKEY, USERNAME, PASSWORD
 */
enum TransportOption: string
{
    case CONSUMER = 'consumer';
    case BATCHING = 'batching';
    case MAX_BATCH_TIMEOUT = 'max_batch_timeout';
    case CONNECTION_TIMEOUT = 'connection_timeout';
    case PING_AFTER_IDLE = 'ping_after_idle';
    case REQUEST_TIMEOUT = 'request_timeout';
    case MAX_ACK_PENDING = 'max_ack_pending';
    case INACTIVE_THRESHOLD = 'inactive_threshold';
    case REPLAY_POLICY = 'replay_policy';
    case STREAM_MAX_AGE = 'stream_max_age';
    case STREAM_MAX_BYTES = 'stream_max_bytes';
    case STREAM_MAX_MESSAGES = 'stream_max_messages';
    case STREAM_MAX_MESSAGES_PER_SUBJECT = 'stream_max_messages_per_subject';
    case STREAM_MAX_MESSAGE_SIZE = 'stream_max_message_size';
    case STREAM_MAX_CONSUMERS = 'stream_max_consumers';
    case STREAM_STORAGE = 'stream_storage';
    case STREAM_REPLICAS = 'stream_replicas';
    case STREAM_RETENTION = 'stream_retention';
    case STREAM_DISCARD = 'stream_discard';
    case STREAM_DUPLICATE_WINDOW = 'stream_duplicate_window';
    case STREAM_COMPRESSION = 'stream_compression';
    case STREAM_DESCRIPTION = 'stream_description';
    case STREAM_DENY_DELETE = 'stream_deny_delete';
    case STREAM_DENY_PURGE = 'stream_deny_purge';
    case STREAM_ALLOW_DIRECT = 'stream_allow_direct';
    case STREAM_ALLOW_ROLLUP_HEADERS = 'stream_allow_rollup_headers';
    case RETRY_HANDLER = 'retry_handler';
    case NAK_DELAY = 'nak_delay';
    case ACK_WAIT = 'ack_wait';
    case MAX_DELIVER = 'max_deliver';
    case BACKOFF = 'backoff';
    case SCHEDULED_MESSAGES = 'scheduled_messages';
    case ACK_SYNC = 'ack_sync';
    case DEDUPLICATE = 'deduplicate';
    case AUTO_SETUP = 'auto_setup';

    case TLS_REQUIRED = 'tls_required';
    case TLS_HANDSHAKE_FIRST = 'tls_handshake_first';
    case TLS_CA_FILE = 'tls_ca_file';
    case TLS_CERT_FILE = 'tls_cert_file';
    case TLS_KEY_FILE = 'tls_key_file';
    case TLS_KEY_PASSPHRASE = 'tls_key_passphrase';
    case TLS_PEER_NAME = 'tls_peer_name';
    case TLS_VERIFY_PEER = 'tls_verify_peer';

    case TOKEN = 'token';
    case JWT = 'jwt';
    case NKEY = 'nkey';
    case USERNAME = 'username';
    case PASSWORD = 'password';
}
