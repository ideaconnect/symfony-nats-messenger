# Test TLS Certificates

These are **test-only** self-signed certificates used exclusively by the functional test suite.

- `ca.pem` — Self-signed Certificate Authority (CN=NATS Test CA, valid 10 years)
- `ca-key.pem` — CA private key (used to sign server/client certs)
- `server-cert.pem` — Server certificate signed by the CA (CN=localhost, SAN=localhost,127.0.0.1)
- `server-key.pem` — Server private key (unencrypted)
- `client-cert.pem` — Client certificate signed by the CA (CN=nats-test-client), used for mTLS tests
- `client-key.pem` — Client private key (unencrypted)

**Do NOT use these certificates in production.**
