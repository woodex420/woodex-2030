"""Summarise a run-suite.sh output directory.

Prints the totals per group and EVERY failing test with its cause. A failure is "explained" only when its own
output shows a known limit of the sandbox PHP build; anything else is printed as UNEXPLAINED and the exit code is 1.

Why by cause and not by name: on 2026-11-11 a test that already failed here for a missing extension kept failing
after a change on this branch, for a new reason. The list of failing names did not change, so the regression was only
caught by the MySQL run in CI.

Usage: python3 tools/preview/sandbox/suite-summary.py <out-dir>     (PHP binary: $WOODEX_PHP, default php)
"""
import os, re, subprocess, sys

out = sys.argv[1]
ansi = re.compile(r'\x1b\[[0-9;]*m')
GROUPS = ('unit', 'feature-a-f', 'feature-g-p', 'feature-q-z', 'arch')

# Known limits of the static PHP 8.4 build used in the sandbox. An excuse counts only while the limit is real:
# the PHP binary is asked, so the same failure on a complete PHP is reported as UNEXPLAINED.
PHP = os.environ.get("WOODEX_PHP", "php")
try:
    probe = subprocess.run([PHP, '-r', 'echo (int) extension_loaded("xmlwriter"), (int) in_array("mysql", PDO::getAvailableDrivers(), true);'],
                           capture_output=True, text=True, timeout=20).stdout.strip()
except Exception:
    probe = ''
HAS_XMLWRITER, HAS_PDO_MYSQL = (probe[:1] == '1', probe[1:2] == '1') if len(probe) == 2 else (True, True)

def cause(block: str, cls: str) -> str:
    if not HAS_XMLWRITER and 'enable the xmlwriter extension' in block:
        return 'sandbox PHP: no xmlwriter (QR code)'
    if not HAS_PDO_MYSQL and cls.endswith('InstallerTest') and 'mysqlForm(' in block:
        # Installer::checkDatabase refuses MySQL when PDO has no mysql driver, before the faked server is asked.
        return 'sandbox PHP: no pdo_mysql (installer)'
    return 'UNEXPLAINED'

tot = {}; rows = []
for g in GROUPS:
    p = os.path.join(out, g + '.log')
    if not os.path.exists(p):
        print(f'{g:12s} no log'); continue
    t = ansi.sub('', open(p, encoding='utf-8', errors='replace').read())
    m = re.search(r'Tests:\s+(.*)', t); d = re.search(r'Duration:\s+(\S+)', t)
    print(f"{g:12s} {m.group(1).strip() if m else 'NO SUMMARY LINE'}  [{d.group(1) if d else '?'}]")
    if m:
        for n, k in re.findall(r'(\d+) (failed|passed|skipped|warnings?|deprecated|risky|incomplete|todos?|notices?)', m.group(1)):
            tot[k] = tot.get(k, 0) + int(n)
        a = re.search(r'\((\d+) assertions\)', m.group(1)); tot['assertions'] = tot.get('assertions', 0) + (int(a.group(1)) if a else 0)
    for block in re.split(r'(?m)^(?=\s+FAILED\s+Tests\\)', t)[1:]:
        head = block.strip().split('\n')[0]
        hm = re.match(r'FAILED\s+(Tests\\\S+)\s+>\s*(.*?)(?:\s{2,}(\S+))?\s*$', head)
        cls = hm.group(1) if hm else '?'; desc = (hm.group(2) if hm else head).strip(); exc = (hm.group(3) or '') if hm else ''
        lines = [l.strip() for l in block.strip().split('\n')[1:] if l.strip()]
        at = re.search(r'at (tests/\S+:\d+)', block)
        rows.append((g, cls.replace('Tests\\Feature\\', '').replace('Tests\\Unit\\', 'Unit\\'), desc, exc, lines[0][:110] if lines else '', at.group(1) if at else '', cause(block, cls)))

print('TOTAL:', tot)
print(f'PHP used for the excuses: xmlwriter={HAS_XMLWRITER} pdo_mysql={HAS_PDO_MYSQL}')
print(f"failing tests: {len(rows)} listed, {tot.get('failed', 0)} counted by Pest")
by = {}
for r in rows: by[r[6]] = by.get(r[6], 0) + 1
for k, v in sorted(by.items()): print(f'  {v:3d}  {k}')
for g, cls, desc, exc, first, at, why in rows:
    print(f"  [{why}] {cls} > {desc} {exc} | {at} | {first}")
bad = by.get('UNEXPLAINED', 0) + (0 if len(rows) == tot.get('failed', 0) else 1)
if bad: print('\nUNEXPLAINED FAILURES — read them before trusting this run.')
sys.exit(1 if bad else 0)
