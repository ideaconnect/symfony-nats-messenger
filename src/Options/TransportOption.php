<?php

namespace IDCT\NatsMessenger\Options;

/**
 * Enum of all recognized transport configuration option keys.
 *
 * Each case maps to a string key used in DSN query parameters, YAML transport config,
 * and the internal merged options array. Grouped by function:
 *
 * **Consumer & Batching:** CONSUMER, BATCHING, MAX_BATCH_TIMEOUT, CONNECTION_TIMEOUT
 * **Stream Limits:** STREAM_MAX_AGE, STREAM_MAX_BYTES, STREAM_MAX_MESSAGES, STREAM_REPLICAS
 * **Retry Strategy:** RETRY_HANDLER
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
    case STREAM_MAX_AGE = 'stream_max_age';
    case STREAM_MAX_BYTES = 'stream_max_bytes';
    case STREAM_MAX_MESSAGES = 'stream_max_messages';
    case STREAM_REPLICAS = 'stream_replicas';
    case RETRY_HANDLER = 'retry_handler';

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
