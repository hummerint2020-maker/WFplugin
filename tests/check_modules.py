"""Module boundaries (modular monolith, step 1): what crosses from one module into another.

docs/architecture/modules.json says which module each includes/trait-*.php file belongs to and which
module owns each table. This counts, for each module:
  - tables of another module it reads or writes directly (instead of asking that module);
  - functions of another module it calls (all traits share one object, so nothing stops it).
Calls into "platform" (settings, permissions, audit, templates: the shared kernel) and calls to a
module's public functions (its "api" in modules.json) are allowed and reported apart.

The counts may only go down: the check fails when a pair of modules crosses more than the baseline
(docs/architecture/module-baseline.json) or a new pair appears. After moving code behind a module's
own functions, run with --update to lower the baseline.

Usage: python3 tests/check_modules.py [--update] [--report out.json]
"""
import json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'workforce-one')
MAP = os.path.join(ROOT, 'docs', 'architecture', 'modules.json')
BASELINE = os.path.join(ROOT, 'docs', 'architecture', 'module-baseline.json')
KERNEL = 'platform'

# Tables named through a property or a helper instead of their name.
PROPS = {'employees': 'employees', 'schedule': 'schedule', 'time_logs': 'time_logs', 'leaves': 'leave_requests',
         'locations': 'locations', 'notifications': 'notifications', 'audit': 'audit_log', 'company_calendar': 'company_calendar'}
HELPERS = {'cx_table': 'attendance_corrections', 'attempts_table': 'signin_attempts', 'task_table': 'tasks',
           'push_table': 'push_subscriptions', 'payroll_table': 'pay_rates'}
LITERAL = re.compile(r"(?:prefix\s*\.\s*['\"]|prefix\}|\{\$p\}|\$p\s*\.\s*['\"])ews_([a-z0-9_]+)")
DEF = re.compile(r'function\s+&?([A-Za-z0-9_]+)\s*\(')
CALL = re.compile(r'\$this->([A-Za-z0-9_]+)\s*\(')
PROP = re.compile(r'\$this->(' + '|'.join(PROPS) + r')\b(?!\s*\()')


def strip_comments(src):
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    return re.sub(r'(?m)^\s*//.*$', '', src)


def load():
    with open(MAP) as f:
        m = json.load(f)['modules']
    trait_mod, table_mod, api = {}, {}, set()
    for mod, d in m.items():
        api.update(d.get('api', []))
        for t in d['traits']:
            trait_mod[t] = mod
        for t in d['tables']:
            table_mod[t] = mod
    return m, trait_mod, table_mod, api


def analyse():
    modules, trait_mod, table_mod, api = load()
    files = {}
    for name in sorted(os.listdir(os.path.join(PLUGIN, 'includes'))):
        if name.startswith('trait-') and name.endswith('.php'):
            files[name[6:-4]] = strip_comments(open(os.path.join(PLUGIN, 'includes', name)).read())
    problems = [f'trait-{t}.php is in no module' for t in files if t not in trait_mod]
    problems += [f'{t} in modules.json has no file' for t in trait_mod if t not in files]
    # Who defines each function (a name defined in two modules is not counted).
    owner = {}
    for t, src in files.items():
        for fn in DEF.findall(src):
            mod = trait_mod.get(t)
            owner[fn] = mod if owner.get(fn, mod) == mod else None
    for fn in DEF.findall(strip_comments(open(os.path.join(PLUGIN, 'employee-schedule-manager.php')).read())):
        owner.setdefault(fn, KERNEL)
    known = set(table_mod)
    tables, calls, kernel, api_calls, size = {}, {}, {}, {}, {}
    for t, src in files.items():
        mod = trait_mod.get(t)
        if not mod:
            continue
        size[mod] = size.get(mod, 0) + src.count('\n')
        used = [PROPS[p] for p in PROP.findall(src)] + [HELPERS[h] for h in re.findall(r'\$this->(' + '|'.join(HELPERS) + r')\(', src)]
        used += [x for x in LITERAL.findall(src) if x in known]
        for tb in used:
            to = table_mod.get(tb)
            if to and to != mod:
                key = f'{mod}->{to}'
                tables.setdefault(key, {})
                tables[key][tb] = tables[key].get(tb, 0) + 1
        for fn in CALL.findall(src):
            to = owner.get(fn)
            if not to or to == mod:
                continue
            bucket = kernel if to == KERNEL else (api_calls if fn in api else calls)
            key = f'{mod}->{to}'
            bucket.setdefault(key, {})
            bucket[key][fn] = bucket[key].get(fn, 0) + 1
    unowned = sorted({x for src in files.values() for x in LITERAL.findall(src)} - known - {''})
    return {'modules': {k: {'label': v['label'], 'lines': size.get(k, 0), 'traits': len(v['traits']), 'tables': v['tables']} for k, v in modules.items()},
            'tables': tables, 'calls': calls, 'kernel_calls': kernel, 'api_calls': api_calls, 'problems': problems, 'not_tables': unowned}


def totals(section):
    return {k: sum(v.values()) for k, v in section.items()}


def main():
    r = analyse()
    current = {'tables': totals(r['tables']), 'calls': totals(r['calls'])}
    if '--report' in sys.argv:
        with open(sys.argv[sys.argv.index('--report') + 1], 'w') as f:
            json.dump(r, f, indent=1, sort_keys=True)
    for p in r['problems']:
        print('MAP', p)
    print('module          lines  other tables used  other functions called')
    for mod, d in sorted(r['modules'].items(), key=lambda x: -x[1]['lines']):
        t = sum(n for k, n in current['tables'].items() if k.startswith(mod + '->'))
        c = sum(n for k, n in current['calls'].items() if k.startswith(mod + '->'))
        print(f'{mod:15} {d["lines"]:6} {t:18} {c:23}')
    print('total crossings: %d table uses, %d function calls (allowed: %d calls into platform, %d to modules\' public functions)'
          % (sum(current['tables'].values()), sum(current['calls'].values()), sum(totals(r['kernel_calls']).values()), sum(totals(r['api_calls']).values())))
    if '--update' in sys.argv or not os.path.exists(BASELINE):
        with open(BASELINE, 'w') as f:
            json.dump(current, f, indent=1, sort_keys=True)
            f.write('\n')
        print('baseline written:', os.path.relpath(BASELINE, ROOT))
        return 1 if r['problems'] else 0
    with open(BASELINE) as f:
        base = json.load(f)
    worse, better = [], []
    for kind in ('tables', 'calls'):
        for k, n in current[kind].items():
            b = base.get(kind, {}).get(k, 0)
            if n > b:
                worse.append(f'{kind}: {k} {b} -> {n}')
            elif n < b:
                better.append(f'{kind}: {k} {b} -> {n}')
        better += [f'{kind}: {k} {b} -> 0' for k, b in base.get(kind, {}).items() if k not in current[kind] and b]
    for w in worse:
        print('MORE THAN BEFORE', w)
    for b in better:
        print('fewer (run with --update to keep it so)', b)
    ok = not worse and not r['problems']
    print('OK: no module crosses into another more than before' if ok else 'FAILED: module boundaries crossed more than the baseline')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
