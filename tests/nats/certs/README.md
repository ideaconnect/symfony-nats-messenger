# Test TLS Certificates

These are **test-only** self-signed certificates used exclusively by the functional test suite.

- `ca.pem` — Self-signed Certificate Authority (CN=NATS Test CA, valid 10 years)
- `server-cert.pem` — Server certificate signed by the CA (CN=localhost, SAN=localhost,127.0.0.1)
- `server-key.pem` — Server private key (unencrypted)

**Do NOT use these certificates in production.**
