#!/usr/bin/env bash
# Runs every examples/*.php (except the support files starting with "_") against a running NATS server and
# reports pass / skip / fail. Each example mirrors a README section, so this doubles as a check that the
# documented behaviour still works.
#
# Quick start: composer nats:start, then composer examples (or bash scripts/run-examples.sh).
# NATS_DSN points the examples at another server ("nats-jetstream://[user:password@]host:port").
# EXAMPLES_STRICT=1 (used in CI) makes a skipped example fail the run too. EXAMPLE_TIMEOUT overrides the
# per-example timeout in seconds.
set -uo pipefail
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

timeout_s="${EXAMPLE_TIMEOUT:-60}"
strict="${EXAMPLES_STRICT:-0}"
case "${strict,,}" in 1|true|yes|on) strict=1 ;; *) strict=0 ;; esac

pass=0
skip=0
fail=0
failed=""
skipped=""

for file in examples/*.php; do
  name="$(basename "$file" .php)"
  case "$name" in _*) continue ;; esac

  out="$(timeout "$timeout_s" php "$file" 2>&1)"
  rc=$?
  summary="$(printf '%s\n' "$out" | grep -m1 -E '^(OK|SKIP)' || printf '%s' "$out" | tail -1)"

  if [ "$rc" -ne 0 ]; then
    fail=$((fail + 1))
    failed="$failed $name"
    printf 'FAIL  %-22s exit %d: %s\n' "$name" "$rc" "$summary"
    printf '%s\n' "$out" | tail -20 | sed 's/^/      /'
  elif printf '%s\n' "$summary" | grep -q '^SKIP'; then
    skip=$((skip + 1))
    skipped="$skipped $name"
    printf 'SKIP  %-22s %s\n' "$name" "$summary"
  else
    pass=$((pass + 1))
    printf 'PASS  %-22s %s\n' "$name" "$summary"
  fi
done

strict_label=""
[ "$strict" = "1" ] && strict_label=" (strict)"
echo
echo "examples: ${pass} passed, ${skip} skipped, ${fail} failed${strict_label}"

status=0
if [ -n "$failed" ]; then
  echo "failed:$failed"
  status=1
fi
if [ "$strict" = "1" ] && [ -n "$skipped" ]; then
  echo "skipped (strict mode treats these as failures):$skipped"
  status=1
fi

exit "$status"
