"""Fail if the System messages catalog is stale (3.31.166, docs/tasks/system-messages.md).

1. workforce-one/languages/workforce-one-catalog.php must equal a fresh `php tools/build_message_catalog.php --stdout`
   (run the tool after changing a text).
2. Every key in src/Messages/ImportantMessages.php must be in the catalog (the text was not changed in the code).
3. The shipped .mo and .l10n.php must be the build of the .po (`python3 tools/po_compile.py --check`).

Usage: python3 tests/check_message_catalog.py
"""
import re, subprocess, sys

CATALOG = 'workforce-one/languages/workforce-one-catalog.php'
IMPORTANT = 'workforce-one/src/Messages/ImportantMessages.php'
problems = []

fresh = subprocess.run(['php', 'tools/build_message_catalog.php', '--stdout'], capture_output=True, text=True, check=True).stdout
shipped = open(CATALOG, encoding='utf-8').read()
if fresh != shipped:
    problems.append(f'{CATALOG} is stale: run php tools/build_message_catalog.php')

keys = subprocess.run(['php', '-r', "define('ABSPATH', 1); foreach (array_keys(require '%s') as $k) echo bin2hex((string) $k), \"\\n\";" % CATALOG],
                      capture_output=True, text=True, check=True).stdout.split()
known = {bytes.fromhex(k).decode('utf-8') for k in keys}
important = subprocess.run(['php', '-r', "define('ABSPATH', 1); require 'workforce-one/src/autoload.php'; foreach (array_keys(WorkforceOne\\Messages\\ImportantMessages::KINDS) as $k) echo bin2hex((string) $k), \"\\n\";"],
                           capture_output=True, text=True, check=True).stdout.split()
for k in important:
    text = bytes.fromhex(k).decode('utf-8')
    if text not in known:
        problems.append(f'{IMPORTANT}: not in the catalog (changed in the code?): {text[:80]}')

po = subprocess.run([sys.executable, 'tools/po_compile.py', '--check'], capture_output=True, text=True)
if po.returncode != 0:
    problems.append('languages: the .mo or .l10n.php is not the build of the .po: run python3 tools/po_compile.py (' + po.stdout.strip() + ')')

for p in problems:
    print(p)
print(f'{len(known)} texts, {len(important)} important keys, {len(problems)} problems')
sys.exit(1 if problems else 0)
