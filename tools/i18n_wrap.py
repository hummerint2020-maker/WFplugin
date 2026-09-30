"""Wrap plain text in the HTML parts of a PHP template region with esc_html_e().

Usage: python3 tools/i18n_wrap.py <file> <start-marker> <end-marker> [--apply]

Only text that sits in HTML (outside <?php ... ?>) between the two markers is touched.
Leading/trailing symbols (emoji, arrows, "·", spaces) stay outside the translated string.
Without --apply it only prints what it would change, so every run can be reviewed.
"""
import re, sys

DOMAIN = 'workforce-one'
LEAD = re.compile(r'^([^A-Za-z]*)(.*?)([^A-Za-z.!?…:)%\'’]*)$', re.S)


def php_str(t):
    return "'" + t.replace('\\', '\\\\').replace("'", "\\'") + "'"


def wrap_text(tok, log):
    if not re.search(r'[A-Za-z]{2,}', tok):
        return tok
    m = LEAD.match(tok)
    pre, core, post = m.group(1), m.group(2), m.group(3)
    if not core.strip() or re.search(r'[&{}$="+;]|\w\.\w+\(', core):
        return tok
    log.append(core)
    return pre + "<?php esc_html_e(" + php_str(core) + ",'" + DOMAIN + "'); ?>" + post


def process(seg, log):
    """Walk the region; the region starts in PHP context (inside a function body).
    Tracks whether HTML text is inside a tag, including tags split by <?php ?> blocks."""
    parts = re.split(r'(\?>|<\?php)', seg)
    in_php, in_tag = True, False
    out = []
    for p in parts:
        if p == '?>':
            in_php = False; out.append(p); continue
        if p == '<?php':
            in_php = True; out.append(p); continue
        if in_php:
            out.append(p); continue
        buf, i = [], 0
        while i < len(p):
            if in_tag:
                j = p.find('>', i)
                if j < 0:
                    buf.append(p[i:]); i = len(p); break
                buf.append(p[i:j + 1]); i = j + 1; in_tag = False
            else:
                j = p.find('<', i)
                text = p[i:] if j < 0 else p[i:j]
                buf.append(wrap_text(text, log))
                if j < 0:
                    i = len(p); break
                i = j; in_tag = True
        out.append(''.join(buf))
    return ''.join(out)


if __name__ == '__main__':
    path, start, end = sys.argv[1], sys.argv[2], sys.argv[3]
    src = open(path, encoding='utf-8').read()
    a = src.index(start); b = src.index(end, a)
    log = []
    new = process(src[a:b], log)
    for l in log:
        print('  ' + l)
    print(len(log), 'strings')
    if '--apply' in sys.argv:
        open(path, 'w', encoding='utf-8').write(src[:a] + new + src[b:])
