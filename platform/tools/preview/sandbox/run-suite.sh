#!/bin/sh
# Run the Pest suite in the source project's five groups, on SQLite in memory (the phpunit.xml default).
# Nothing here touches a real database, mail or a provider.
#
# Usage, from a platform/ directory that has vendor/ installed:
#   sh tools/preview/sandbox/run-suite.sh <out-dir>
#   python3 tools/preview/sandbox/suite-summary.py <out-dir>
# PHP binary: $WOODEX_PHP (default: php).
OUT="${1:?usage: run-suite.sh <out-dir>}"; PHP="${WOODEX_PHP:-php}"
[ -f vendor/bin/pest ] || { echo "run this from a platform/ directory with vendor/ installed" >&2; exit 2; }
mkdir -p "$OUT"
export APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_URL=http://localhost
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs storage/app/private bootstrap/cache
: > "$OUT/results.tsv"
for group in unit feature-a-f feature-g-p feature-q-z arch; do
  files=$(python3 - "$group" <<'PY'
import os, sys
g = sys.argv[1]
out = []
for base in ('tests/Unit', 'tests/Feature'):
    for root, _, names in os.walk(base):
        for n in names:
            if n.endswith('Test.php'): out.append(os.path.join(root, n))
out.append('tests/ArchTest.php')
def group(f):
    if f == 'tests/ArchTest.php': return 'arch'
    if f.startswith('tests/Unit/'): return 'unit'
    c = f[len('tests/Feature/'):][0].lower()
    return 'feature-a-f' if c <= 'f' else 'feature-g-p' if c <= 'p' else 'feature-q-z'
print(' '.join(sorted(f for f in out if os.path.exists(f) and group(f) == g)))
PY
)
  start=$(date +%s)
  # shellcheck disable=SC2086
  "$PHP" -d memory_limit=2G vendor/bin/pest --compact --display-all-issues $files > "$OUT/$group.log" 2>&1
  code=$?
  printf '%s\t%s\t%ss\t%s files\n' "$group" "$code" "$(( $(date +%s) - start ))" "$(echo $files | wc -w)" >> "$OUT/results.tsv"
  echo "GROUP $group exit=$code in $(( $(date +%s) - start ))s"
done
echo "SUITE DONE — now read it by cause: python3 tools/preview/sandbox/suite-summary.py $OUT"
