"""Layered modules stay layered (3.31.127, docs/tasks/layered-modules.md §2.1).

docs/architecture/modules.json lists, per module, the slices moved to Controllers → Services → Stores ("layered"). For each:
  L1  a layered table is queried only by its Store: the other files' references are counted per file
      (docs/architecture/layer-baseline.json) and may only go down; a new file is a failure;
  L2  the Services are free of transport and SQL: no $wpdb, globals, superglobals, echo/exit/redirects/nonces, hooks,
      the plugin object, the current user or the clock;
  L3  the Store touches its slice's tables only and nothing of other modules;
  L4  the Services and the Store use only their module, Platform, Support and other modules' "exports";
  L5  the moved functions in includes/ run no SQL, and a trait listed in traits_clean has no "global $wpdb".
Each rule is first run on a bad example of its own (a rule that cannot fail fails the check).
Usage: python3 tests/check_layers.py [--update]   (--update writes the baseline after a release lowered it)
"""
import json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'workforce-one')
MODULES = json.load(open(os.path.join(ROOT, 'docs', 'architecture', 'modules.json')))['modules']
BASELINE = os.path.join(ROOT, 'docs', 'architecture', 'layer-baseline.json')
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from check_modules import PROPS, strip_comments  # noqa: E402

LIT = re.compile(r"""(?:prefix\s*\.\s*['"]|prefix\}|\{\$p\}|\$p\s*\.\s*['"])ews_([a-z0-9_]+)""")
PROP = re.compile(r'\$this->(' + '|'.join(PROPS) + r')\b(?!\s*\()')
L2_BAD = [r'\bglobal\s+\$', r'\$wpdb\b', r'\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b', r'\becho\b', r'\bprint\b', r'\bexit\b', r'\bdie\b',
          r'\bwp_die\s*\(', r'\bwp_(?:safe_)?redirect\s*\(', r'\bheader\s*\(', r'\bsetcookie\s*\(', r'\bcheck_admin_referer\s*\(', r'\bwp_verify_nonce\s*\(',
          r'\badd_action\s*\(', r'\badd_filter\s*\(', r'\bEWS_', r'\b(?:include|require)(?:_once)?\b', r'\bget_current_user_id\s*\(', r'\bcurrent_time\s*\(', r'\bwpdb\b']
USE = re.compile(r'^\s*use\s+WorkforceOne\\([A-Za-z0-9_]+)\\([A-Za-z0-9_\\]+)\s*;', re.M)
FUNC = re.compile(r'function\s+&?([A-Za-z0-9_]+)\s*\(')


def tables_in(src):
    return [PROPS[p] for p in PROP.findall(src)] + LIT.findall(src)


GUARD = re.compile(r"if\s*\(\s*!\s*defined\(\s*'ABSPATH'\s*\)\s*\)\s*exit\s*;")


def l2(src):
    src = GUARD.sub('', src)   # the usual "loaded by WordPress only" guard
    return [p for p in L2_BAD if re.search(p, src)]


def l3(src, own_ns, tables):
    bad = [t for t in LIT.findall(src) if t not in tables] + [t for t in (PROPS[p] for p in PROP.findall(src)) if t not in tables]
    bad += ['use ' + m[0] for m in USE.findall(src) if m[0] != own_ns]
    bad += ['EWS_'] if re.search(r'\bEWS_', src) else []
    return bad


def l4(src, own_ns, exports):
    return ['use %s\\%s' % m for m in USE.findall(src) if m[0] not in (own_ns, 'Platform', 'Support') and 'WorkforceOne\\%s\\%s' % m not in exports]


def body(src, name):
    m = re.search(r'function\s+' + re.escape(name) + r'\s*\(', src)
    if not m:
        return None
    i = src.index('{', m.end())
    depth = 0
    for j in range(i, len(src)):
        depth += {'{': 1, '}': -1}.get(src[j], 0)
        if depth == 0:
            return src[i:j + 1]
    return src[i:]


def self_test():
    ok = True
    ok &= bool(l2("<?php $x=$wpdb->get_var('x'); echo 1;"))
    ok &= not l2("<?php if (!defined('ABSPATH')) exit; return $this->store->find($id, $date);")
    ok &= bool(l3("<?php use WorkforceOne\\People\\X; $t=$db->prefix.'ews_employees';", 'Schedule', ['schedule']))
    ok &= not l3("<?php $t=$db->prefix . 'ews_schedule';", 'Schedule', ['schedule'])
    ok &= bool(l4("<?php\nuse WorkforceOne\\Payroll\\Run;\n", 'Schedule', []))
    ok &= not l4("<?php\nuse WorkforceOne\\Platform\\Clock;\n", 'Schedule', [])
    ok &= bool(tables_in("<?php $wpdb->get_row(\"SELECT * FROM {$this->schedule}\");"))
    ok &= (body("<?php function a(){ if(1){ x(); } } function b(){}", 'a') or '').count('{') == 2
    return ok


def read(rel):
    return strip_comments(open(os.path.join(ROOT, rel), encoding='utf-8').read())


def main():
    if not self_test():
        print('FAILED: a layer rule no longer catches its own bad example')
        return 1
    fails = []
    exports = [e for m in MODULES.values() for e in m.get('exports', [])]
    layered_tables, own_files = {}, set()
    for name, mod in MODULES.items():
        for sl in mod.get('layered', []):
            ns = name.capitalize() if name != 'people' else 'Employees'
            ns = sl.get('namespace', ns)
            for t in sl['tables']:
                layered_tables[t] = name + '.' + sl['slice']
            own_files.add(sl['store'])
            for f in sl['services']:
                src = read(f)
                for p in l2(src):
                    fails.append('L2 %s: %s' % (f, p))
                for u in l4(src, ns, exports):
                    fails.append('L4 %s: %s (not Platform, Support, its module or an export)' % (f, u))
            src = read(sl['store'])
            for b in l3(src, ns, sl['tables']):
                fails.append('L3 %s: %s (a Store touches only its slice)' % (sl['store'], b))
            for u in l4(src, ns, exports):
                fails.append('L4 %s: %s' % (sl['store'], u))
            for trait in sl.get('traits_clean', []):
                rel = 'workforce-one/includes/trait-%s.php' % trait
                if re.search(r'global\s+\$wpdb', read(rel)):
                    fails.append('L5 %s: "global $wpdb" in a clean trait' % rel)
            for fn in sl.get('moved', []):
                found = False
                for d in ('includes',):
                    for f in sorted(os.listdir(os.path.join(PLUGIN, d))):
                        rel = 'workforce-one/%s/%s' % (d, f)
                        b = body(read(rel), fn)
                        if b is None:
                            continue
                        found = True
                        if re.search(r'\$wpdb\b', b) or tables_in(b):
                            fails.append('L5 %s: %s() still runs SQL (it must call its Service)' % (rel, fn))
                if not found:
                    fails.append('L5 %s(): not found in includes/ (renamed? update modules.json)' % fn)
    # L1: references to layered tables outside their Store, per file.
    counts = {}
    for d in ('includes', 'src', 'templates'):
        for base, _, files in os.walk(os.path.join(PLUGIN, d)):
            for f in files:
                if not f.endswith('.php'):
                    continue
                rel = os.path.relpath(os.path.join(base, f), ROOT)
                if rel in own_files or rel.endswith('Schema.php'):
                    continue
                n = sum(1 for t in tables_in(read(rel)) if t in layered_tables)
                if n:
                    counts[rel] = n
    if '--update' in sys.argv:
        json.dump({'_about': 'check_layers.py L1: references to layered tables outside their Store, per file; may only go down', 'files': counts},
                  open(BASELINE, 'w'), indent=2, sort_keys=True)
        print('baseline written:', os.path.relpath(BASELINE, ROOT))
        return 0
    base = json.load(open(BASELINE))['files'] if os.path.exists(BASELINE) else {}
    for rel, n in sorted(counts.items()):
        if n > base.get(rel, 0):
            fails.append('L1 %s: %d references to a layered table (baseline %d): use its Service' % (rel, n, base.get(rel, 0)))
    lower = {r: (base[r], counts.get(r, 0)) for r in base if counts.get(r, 0) < base[r]}
    for r, (a, b) in sorted(lower.items()):
        print('fewer (run with --update to keep it so): %s %d -> %d' % (r, a, b))
    print('layered slices: %s; references outside their Store: %d in %d files' % (', '.join(sorted(set(layered_tables.values()))) or 'none', sum(counts.values()), len(counts)))
    for f in fails:
        print('FAIL ' + f)
    print('OK: the layered slices stay layered' if not fails else 'FAILED: %d' % len(fails))
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
