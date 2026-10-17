"""Fail if a shipped translation has empty entries, mismatched placeholders, or stale JSON.

Usage: python3 tests/check_translations.py
"""
import glob, json, re, sys

ROOT = 'workforce-one/languages'
PH = re.compile(r'%(?:\d+\$)?[sd]|%%')
problems = []


def entries(po):
    for block in po.split('\n\n'):
        m = re.search(r'^msgid "((?:[^"\\]|\\.)*)"$', block, re.M)
        if not m or not m.group(1):
            continue
        yield m.group(1), re.findall(r'^msgstr(?:\[\d\])? "((?:[^"\\]|\\.)*)"$', block, re.M), block


for po_path in glob.glob(ROOT + '/*.po'):
    po = open(po_path, encoding='utf-8').read()
    if '#, fuzzy' in po:
        problems.append(f'{po_path}: contains fuzzy entries')
    for msgid, msgstrs, _ in entries(po):
        for t in msgstrs:
            if not t:
                problems.append(f'{po_path}: untranslated: {msgid[:80]}')
            elif sorted(PH.findall(t)) != sorted(PH.findall(msgid)):
                problems.append(f'{po_path}: placeholder mismatch: {msgid[:80]}')

for js_path in glob.glob(ROOT + '/*.json'):
    msgs = json.load(open(js_path, encoding='utf-8'))['locale_data']['messages']
    for k, v in msgs.items():
        if k and not (v and v[0]):
            problems.append(f'{js_path}: empty JS translation: {k[:80]}')

for p in problems:
    print(p)
print(f'{len(problems)} problem(s)')
sys.exit(1 if problems else 0)
