"""Compiles workforce-one/languages/workforce-one-ar.po into the .mo and .l10n.php files WordPress loads, byte for byte as
`wp i18n make-mo` and `wp i18n make-php` (WP-CLI 2.12) write them, for a machine without WP-CLI (3.31.166).
`--check` proves it first: it rebuilds both files from the .po and fails if they differ from the shipped ones.

Usage: python3 tools/po_compile.py [--check]
"""
import os, re, struct, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LANG = os.path.join(ROOT, 'workforce-one', 'languages')
PO = os.path.join(LANG, 'workforce-one-ar.po')


def unq(s):
    out, i = [], 0
    s = s[1:-1]
    while i < len(s):
        c = s[i]
        if c == '\\' and i + 1 < len(s):
            n = s[i + 1]
            out.append({'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\', '0': '\0'}.get(n, '\\' + n))
            i += 2
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def parse(path):
    """Entries in file order: dict(ctx, id, plural, strs) (strs: list; one item unless plural)."""
    entries, cur, field = [], None, None
    def flush():
        if cur is not None and 'id' in cur:
            entries.append(cur)
    for line in open(path, encoding='utf-8').read().split('\n'):
        line = line.rstrip('\r')
        if not line.strip():
            flush(); cur, field = None, None
            continue
        if line.startswith('#'):
            continue
        m = re.match(r'^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$', line)
        if m:
            if cur is None or (m.group(1) in ('msgctxt',) and 'id' in cur) or (m.group(1) == 'msgid' and 'id' in cur):
                flush(); cur = {'ctx': None, 'plural': None, 'strs': {}}
            k, n, val = m.group(1), m.group(2), unq(m.group(3))
            if k == 'msgctxt':
                cur['ctx'] = val; field = ('ctx',)
            elif k == 'msgid':
                cur['id'] = val; field = ('id',)
            elif k == 'msgid_plural':
                cur['plural'] = val; field = ('plural',)
            else:
                idx = int(n) if n is not None else 0
                cur['strs'][idx] = val; field = ('strs', idx)
            continue
        if line.startswith('"') and cur is not None and field:
            v = unq(line)
            if field[0] == 'strs':
                cur['strs'][field[1]] += v
            else:
                cur[field[0]] = (cur[field[0]] or '') + v
    flush()
    for e in entries:
        e['strs'] = [e['strs'][i] for i in sorted(e['strs'])]
    return entries


def key(e):
    k = e['id'] if e['ctx'] is None else e['ctx'] + '\x04' + e['id']
    return k


def mo(entries):
    items = []
    for e in entries:
        if any(s == '' for s in e['strs']) and e['id'] != '':
            continue
        k = key(e) + ('\0' + e['plural'] if e['plural'] is not None else '')
        items.append((k.encode('utf-8'), '\0'.join(e['strs']).encode('utf-8')))
    items.sort(key=lambda x: x[0])
    n = len(items)
    o_orig = 28
    o_trans = o_orig + n * 8
    offset = o_trans + n * 8
    ids, strs, ot, tt = b'', b'', [], []
    for k, v in items:
        ot.append((len(k), offset + len(ids))); ids += k + b'\0'
    offset2 = offset + len(ids)
    for k, v in items:
        tt.append((len(v), offset2 + len(strs))); strs += v + b'\0'
    out = struct.pack('<7I', 0x950412de, 0, n, o_orig, o_trans, 0, offset)
    for l, o in ot:
        out += struct.pack('<2I', l, o)
    for l, o in tt:
        out += struct.pack('<2I', l, o)
    return out + ids + strs


def php_str(s):
    parts = s.split('\0')
    return ' . "\\0" . '.join("'" + p.replace('\\', '\\\\').replace("'", "\\'") + "'" for p in parts)


def header(entries):
    h = {}
    for e in entries:
        if e['id'] == '' and e['ctx'] is None:
            for line in e['strs'][0].split('\n'):
                if ':' in line:
                    a, b = line.split(':', 1)
                    h[a.strip().lower()] = b.strip()
    return h


def l10n(entries):
    h = header(entries)
    msgs = []
    for e in entries:
        if e['id'] == '' or any(s == '' for s in e['strs']):
            continue
        msgs.append(php_str(key(e)) + '=>' + php_str('\0'.join(e['strs'])))
    head = ["'domain'=>'workforce-one'"]
    for name in ('plural-forms', 'language', 'project-id-version', 'pot-creation-date', 'po-revision-date'):
        if name in h:
            head.append("'%s'=>%s" % (name, php_str(h[name])))
    return '<?php\nreturn [' + ','.join(head) + ",'messages'=>[" + ','.join(msgs) + ']];\n'


def main():
    entries = parse(PO)
    built_mo, built_php = mo(entries), l10n(entries)
    mo_path, php_path = os.path.join(LANG, 'workforce-one-ar.mo'), os.path.join(LANG, 'workforce-one-ar.l10n.php')
    if '--check' in sys.argv:
        a = open(mo_path, 'rb').read() == built_mo
        b = open(php_path, encoding='utf-8').read() == built_php
        print('mo identical:', a, '| l10n.php identical:', b)
        return 0 if a and b else 1
    open(mo_path, 'wb').write(built_mo)
    open(php_path, 'w', encoding='utf-8').write(built_php)
    print('%d entries' % len(entries))
    return 0


if __name__ == '__main__':
    sys.exit(main())
