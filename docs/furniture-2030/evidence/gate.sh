#!/usr/bin/env bash
# The definition of done (AGENTS.md §3) and the single checks behind it, one
# step at a time. Writes one line per step: name, exit code, seconds, and the
# last line the step printed. A step that fails does not stop the run: the
# point is the whole table, not the first red line.
#
#   docs/furniture-2030/evidence/gate.sh <result.tsv>              # everything except the browser run
#   docs/furniture-2030/evidence/gate.sh <result.tsv> --browser    # …and scripts/ci-test.mjs (needs a Chromium, see README.md)
#   docs/furniture-2030/evidence/gate.sh <result.tsv> --only qa-sqlite,qa-pg
#
# Full output of every step is kept in $GATE_LOG_DIR (default: a new temp dir).
# Exit code: 0 when every step that ran exited 0, 1 otherwise.
set -u
cd "$(dirname "$0")/../../.." || exit 2

OUT="${1:?usage: gate.sh <result.tsv> [--browser] [--only a,b,c]}"; shift
BROWSER=0; ONLY=""
while [ $# -gt 0 ]; do
  case "$1" in
    --browser) BROWSER=1 ;;
    --only) shift; ONLY=",${1:-}," ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done

LOGS="${GATE_LOG_DIR:-$(mktemp -d /tmp/woodex-gate.XXXXXX)}"; mkdir -p "$LOGS"
QA_PORT="${GATE_QA_PORT:-3117}"
FAILED=0

changed=$(git status --porcelain | wc -l | tr -d ' ')
{
  printf '# commit %s' "$(git rev-parse HEAD)"
  [ "$changed" != "0" ] && printf ' + working tree (%s changed path(s))' "$changed"
  printf ' | node %s | %s\n' "$(node --version)" "$(date -u +%FT%TZ)"
  printf '# step\texit code\tseconds\tlast line of output\n'
} > "$OUT"

step() { # step <name> <command…>
  local name="$1"; shift
  if [ -n "$ONLY" ] && [ "${ONLY#*,"$name",}" = "$ONLY" ]; then return 0; fi
  local start code last
  start=$(date +%s)
  "$@" > "$LOGS/$name.log" 2>&1
  code=$?
  last=$(grep -v '^[[:space:]]*$' "$LOGS/$name.log" | tail -1 | tr '\t' ' ' | cut -c1-200)
  printf '%s\t%s\t%ss\t%s\n' "$name" "$code" "$(( $(date +%s) - start ))" "$last" >> "$OUT"
  printf '%-28s exit %s  %ss\n' "$name" "$code" "$(( $(date +%s) - start ))"
  [ "$code" = "0" ] || FAILED=1
}

# `npm run qa` talks to a running API and opens the same SQLite file itself.
# Give it a server and a database of its own, and stop exactly that server.
qa_sqlite() {
  local dir pid code
  dir=$(mktemp -d /tmp/woodex-gate-qa.XXXXXX)
  PORT="$QA_PORT" NODE_ENV=test WOODEX_DEMO_SEED=1 WOODEX_DATA_DIR="$dir" \
    node --experimental-sqlite apps/api/server.mjs > "$LOGS/qa-sqlite.server.log" 2>&1 &
  pid=$!
  for _ in $(seq 1 60); do
    node -e "fetch('http://127.0.0.1:$QA_PORT/api/health').then((r)=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" && break
    sleep 0.5
  done
  QA_BASE="http://127.0.0.1:$QA_PORT" WOODEX_DATA_DIR="$dir" node scripts/qa-full.mjs
  code=$?
  kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
  rm -rf "$dir"
  return $code
}

step secret-scan         npm run -s secret-scan
step docs-verify         npm run -s docs:verify
step check-deploy        npm run -s check:deploy
step seed-data-check     npm run -s seed:data:check
step catalog-check       npm run -s catalog:check
step test-catalog-build  npm run -s test:catalog-build
step test-router         npm run -s test:router
step check-migration     npm run -s check:migration
step test-blocks         npm run -s test:blocks
step typecheck-all       npm run -s typecheck:all
step smoke-render        npm run -s smoke:render
step qa-sqlite           qa_sqlite
step qa-pg               npm run -s qa:pg
step test-modules-both-engines npm run -s test:modules
step test-return-id      npm run -s test:return-id
step test-migrate        npm run -s test:migrate
step test-integrity      npm run -s test:integrity
step test-audit          npm run -s test:audit
step test-public         npm run -s test:public
step test-stock          npm run -s test:stock
step test-restore        npm run -s test:restore
step test-audit-log      npm run -s test:audit-log
step test-procurement    npm run -s test:procurement
step test-bills          npm run -s test:bills
step test-production     npm run -s test:production
step test-upgrade        npm run -s test:upgrade
step test-ops            npm run -s test:ops
step test-inventory      npm run -s test:inventory
step test-finance        npm run -s test:finance
step test-crm            npm run -s test:crm
step test-delivery       npm run -s test:delivery
step test-support        npm run -s test:support
step test-credentials    npm run -s test:credentials
step test-throttle       npm run -s test:throttle
step test-catalog        npm run -s test:catalog
step vercel-build        npm run -s vercel:build
step smoke-hostinger     npm run -s smoke:hostinger
step npm-audit-high      npm audit --audit-level=high --omit=dev
if [ "$BROWSER" = "1" ]; then
  step ci-test-all-browser-checks node scripts/ci-test.mjs
fi

echo "results: $OUT   logs: $LOGS"
exit $FAILED
