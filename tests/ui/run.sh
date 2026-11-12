#!/bin/sh
# Every browser test against the local test site (http://127.0.0.1:8081). Needs tests/up.sh's mocks running.
cd "$(dirname "$0")"
fail=0
for t in *_smoke.js script_approve_steady.js; do
  printf '%s: ' "$t"
  out=$(node "$t" 2>&1 | grep -v 'agent-proxy\|googleapis\|accounts.google\|Allowed domains\|For details' | tail -4)
  echo "$out"
  [ "$(echo "$out" | tail -1)" = ok ] || fail=1
done
exit $fail
