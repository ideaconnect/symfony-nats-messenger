#!/usr/bin/env bash
#
# Generates the test-only credentials of the NATS servers the functional suite and the examples log in to: an NKey user
# for the plain, TLS and mTLS servers, and an operator with its accounts and users for the server in operator mode.
# None of it is committed, since security scanners report private keys and JWTs found in a repository, test ones
# included (#9). `composer nats:start` runs this before it starts the servers. Needs docker: nsc comes from
# natsio/nats-box. The seeds of the operator and of the accounts the server knows stay in that container, so nothing can
# sign another user.
#
# Usage: generate.sh [--force]
#   Without --force a complete set that already exists is kept, so running servers keep matching keys.
#   With --force a new set replaces it; restart the servers (composer nats:stop, nats:start) to load it.

set -euo pipefail

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$dir"

if [ "${1:-}" != "--force" ] && [ -s nkey-user.conf ] && [ -s resolver.conf ] && [ -s credentials.json ]; then
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required to generate the test NKey and JWT credentials" >&2
  exit 1
fi

# nsc needs a home it can write to, and the files it leaves in /out belong to the user running this script.
docker run --rm -i --user "$(id -u):$(id -g)" -v "$dir":/out \
  -e HOME=/tmp -e XDG_DATA_HOME=/tmp/nsc -e XDG_CONFIG_HOME=/tmp/nsc/config -e NKEYS_PATH=/tmp/nsc/nkeys \
  natsio/nats-box:0.19.0 sh -eu <<'NSC'
# Runs nsc, for commands that print no key, and shows what it said only when it fails.
nsc_step() {
  output="$(nsc "$@" 2>&1)" || { printf 'nsc %s failed:\n%s\n' "$*" "$output" >&2; exit 1; }
}

# A key that no JWT goes with, of the type $1: the seed comes first, then the public key.
nkey_pair() {
  pair="$(nsc generate nkey "$1")"
  jq -n --arg seed "$(printf '%s\n' "$pair" | sed -n 1p)" --arg public "$(printf '%s\n' "$pair" | sed -n 2p)" \
    '{seed: $seed, public: $public}'
}

# The two values of a user's .creds file, as the transport takes them in its jwt and nkey_seed options.
jwt_pair() {
  jq -n \
    --arg jwt "$(nsc describe user --account "$1" --name "$2" --raw)" \
    --arg seed "$(nsc generate creds --account "$1" --name "$2" | awk '/BEGIN USER NKEY SEED/ { getline; print }')" \
    '{jwt: $jwt, seed: $seed}'
}

nsc_step add operator --name test-operator --sys
nsc_step add account --name test-account
# Limits on the account are what enables JetStream for it in operator mode.
nsc_step edit account --name test-account --js-mem-storage 256M --js-disk-storage 1G
nsc_step add account --name no-jetstream-account
nsc_step add user --account test-account --name test-user
nsc_step add user --account test-account --name bearer-user --bearer
nsc_step add user --account test-account --name expired-user --start 2020-01-01 --expiry 2020-01-02
nsc_step add user --account no-jetstream-account --name no-jetstream-user

nkey_user="$(nkey_pair --user)"
jq -n \
  --argjson nkey_user "$nkey_user" \
  --argjson unknown_user "$(nkey_pair --user)" \
  --argjson account_key "$(nkey_pair --account)" \
  --argjson jwt_user "$(jwt_pair test-account test-user)" \
  --argjson bearer_user "$(jwt_pair test-account bearer-user)" \
  --argjson expired_user "$(jwt_pair test-account expired-user)" \
  --argjson no_jetstream_user "$(jwt_pair no-jetstream-account no-jetstream-user)" \
  '{nkey_user: $nkey_user, unknown_user: $unknown_user, account_key: $account_key, jwt_user: $jwt_user, bearer_user: $bearer_user, expired_user: $expired_user, no_jetstream_user: $no_jetstream_user}' \
  > /tmp/credentials.json

# A failure inside the $(...) above does not stop this script, and an empty value would pass for a key.
jq -e 'all(.[]; all(.[]; . != ""))' /tmp/credentials.json >/dev/null \
  || { echo "A key or a JWT came out empty; see what nsc said above" >&2; exit 1; }

nsc_step generate config --mem-resolver --config-file /tmp/resolver.conf --force

# A set is complete once the last file exists, so that one goes first when a set is replaced. The servers' half, which
# holds no private key, comes next, and then the clients' half.
rm -f /out/credentials.json
printf '# Written by auth/generate.sh, for the tests only.\nNKEY_TEST_USER: "%s"\n' \
  "$(printf '%s' "$nkey_user" | jq -r .public)" > /out/nkey-user.conf
cp /tmp/resolver.conf /out/resolver.conf
cp /tmp/credentials.json /out/credentials.json
chmod 644 /out/nkey-user.conf /out/resolver.conf /out/credentials.json
NSC

echo "Generated the test NKey and JWT credentials in $dir"
