#!/usr/bin/env bash
#
# Generates the test-only TLS material for the functional suite's TLS and mTLS NATS servers: a CA, a
# server certificate for localhost and 127.0.0.1, and a client certificate for the mTLS server, both
# signed by that CA. None of it is committed, since security scanners report private keys found in a
# repository, test ones included (#9). `composer nats:start` runs this before it starts the servers.
#
# Usage: generate.sh [--force]
#   Without --force a complete set that already exists is kept, so running servers keep matching keys.
#   With --force a new set replaces it; restart the servers (composer nats:stop, nats:start) to load it.

set -euo pipefail

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$dir"

if [ "${1:-}" != "--force" ] \
  && [ -f ca.pem ] && [ -f server-cert.pem ] && [ -f server-key.pem ] \
  && [ -f client-cert.pem ] && [ -f client-key.pem ]; then
  exit 0
fi

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl is required to generate the test TLS certificates" >&2
  exit 1
fi

days=825
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Plain genrsa / req / x509 -req with extension files, rather than newer shortcuts such as -addext, so
# that LibreSSL (the openssl of macOS) produces the same set.
openssl genrsa -out ca-key.pem 2048 2>/dev/null
openssl req -new -key ca-key.pem -subj "/CN=NATS Test CA" -out "$work/ca.csr"
printf 'basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n' > "$work/ca.ext"
openssl x509 -req -sha256 -days "$days" -in "$work/ca.csr" -signkey ca-key.pem -extfile "$work/ca.ext" \
  -out ca.pem 2>/dev/null

issue() {
  local name="$1" subject="$2" extensions="$3"
  openssl genrsa -out "$name-key.pem" 2048 2>/dev/null
  openssl req -new -key "$name-key.pem" -subj "$subject" -out "$work/$name.csr"
  printf '%b' "$extensions" > "$work/$name.ext"
  openssl x509 -req -sha256 -days "$days" -in "$work/$name.csr" -CA ca.pem -CAkey ca-key.pem \
    -CAserial "$work/ca.srl" -CAcreateserial -extfile "$work/$name.ext" -out "$name-cert.pem" 2>/dev/null
}

issue server "/CN=localhost" \
  'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\nkeyUsage=critical,digitalSignature,keyEncipherment\n'
issue client "/CN=nats-test-client" \
  'extendedKeyUsage=clientAuth\nkeyUsage=critical,digitalSignature,keyEncipherment\n'

# The NATS containers read the files as another user, through a read-only mount.
chmod 644 ./*.pem

echo "Generated the test TLS certificates in $dir"
