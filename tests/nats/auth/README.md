# Test NKey and JWT Credentials

The functional suite and the examples log in to real NATS servers with **test-only** credentials that are generated on
each machine and never committed: security scanners report private keys and JWTs found in a repository, test ones
included (#9). `composer nats:start` generates them before it starts the servers, and so does the Behat context when it
has to start NATS itself. They need docker, as `nsc` comes from `natsio/nats-box`.

```bash
bash tests/nats/auth/generate.sh          # creates the set, or keeps a complete one
bash tests/nats/auth/generate.sh --force  # replaces it; restart the servers to load the new set
```

- `nkey-user.conf` - the servers' half of the NKey user: its public key in the variable `NKEY_TEST_USER`, which
  `nats.conf`, `nats-tls.conf` and `nats-mtls.conf` list as a user.
- `resolver.conf` - the servers' half of operator mode, which `nats-jwt.conf` includes: the JWTs of the operator, the
  system account and two accounts, `test-account`, whose limits enable JetStream, and `no-jetstream-account`.
- `credentials.json` - the clients' half, read by the Behat context and `examples/_bootstrap.php`:

| Key | What it is |
|---|---|
| `nkey_user` | `seed` and `public` of the NKey user the plain, TLS and mTLS servers list |
| `unknown_user` | `seed` and `public` of a user key no server lists |
| `account_key` | `seed` and `public` of an account key no operator signed. It is not a user's, so the transport refuses its seed |
| `jwt_user` | `jwt` and `seed` of `test-user` in `test-account` |
| `bearer_user` | `jwt` and `seed` of a user whose JWT is a bearer token: the server does not check the signature |
| `expired_user` | `jwt` and `seed` of a user whose JWT expired in 2020 |
| `no_jetstream_user` | `jwt` and `seed` of a user in `no-jetstream-account` |

The seeds of the operator and of the accounts the server knows stay in the container that made them, so nothing can sign
another user. Apart from the expired one, the JWTs have no expiry. **Do not use any of this outside the test suite.**
