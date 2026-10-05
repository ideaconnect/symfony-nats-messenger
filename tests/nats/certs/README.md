# Test TLS Certificates

The functional suite's TLS and mTLS NATS servers use **test-only** certificates that are generated on
each machine and never committed: security scanners report private keys found in a repository, test ones
included (#9). `composer nats:start` generates them before it starts the servers, and so does the Behat
context when it has to start NATS itself. They need `openssl`.

```bash
bash tests/nats/certs/generate.sh          # creates the set, or keeps a complete one not about to expire
bash tests/nats/certs/generate.sh --force  # replaces it; restart the servers to load the new set
```

- `ca.pem`, `ca-key.pem` - a certificate authority (CN=NATS Test CA) that signs the two below
- `server-cert.pem`, `server-key.pem` - the servers' certificate (CN=localhost, SAN localhost and 127.0.0.1)
- `client-cert.pem`, `client-key.pem` - the client certificate for the mTLS server (CN=nats-test-client)

Each is valid for 825 days and the keys are unencrypted. **Do not use them outside the test suite.**
