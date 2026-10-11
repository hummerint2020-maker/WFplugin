"""Builds the static HTML mockups in docs/mockups/ (Step 1 of docs/tasks/*.md).

Every screen is written twice, Arabic (RTL) and English, as a full page that loads the plugin's real
stylesheets, fonts and icons (workforce-one/assets/...). Screens that do not exist yet use the
feature's own stylesheet next to the screens (e.g. attendance-corrections/corrections.css), which is
what Step 2 would ship. wp-admin screens load WordPress's admin CSS (_kit/wp-admin-*.css, copied
from WordPress, GPL).

    python3 docs/mockups/_kit/build.py                 # writes into docs/mockups/
    python3 docs/mockups/_kit/build.py --out DIR --assets assets/   # a self-contained copy for publishing

Screens live in _kit/screens_corrections.py, _kit/screens_workers.py and _kit/screens_office.py
(the last starts from snapshots of the real Attendance page in _kit/snap/).
"""
import argparse, html, json, os, re, sys

KIT = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(KIT)                       # docs/mockups
REPO = os.path.dirname(os.path.dirname(ROOT))
ICONS = json.load(open(os.path.join(KIT, 'icons.json')))
# Icons a screen needs that the plugin does not have yet (same 24px grid and stroke as src/Ui/Icons.php).
ICONS.update({
    'camera': '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3.2"/>',
    'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'printer': '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
    'upload': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
    'history': '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    'phone': '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
    'star': '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
    'shield': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
    'eye': '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'minus': '<path d="M5 12h14"/>',
    'moon': '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>',
    'image': '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
    'cash': '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    'wifi-off': '<path d="M2 2l20 20"/><path d="M8.5 16.5a5 5 0 0 1 7 0"/><path d="M5 12.9a10 10 0 0 1 5.2-2.8"/><path d="M14 10.2a10 10 0 0 1 5 2.7"/><path d="M12 20h.01"/>',
    'hat': '<path d="M2 18h20"/><path d="M4 18v-3a8 8 0 0 1 16 0v3"/><path d="M10 7V5h4v2"/>',
    'site': '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01"/>',
    'id': '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2.5"/><path d="M14 10h5M14 14h3"/>',
})


def icon(name, size=22, stroke=1.8, cls=''):
    return ('<svg class="wfo-icon%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" '
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>') % (
                (' ' + cls) if cls else '', size, size, ('%g' % stroke), ICONS.get(name, ICONS['more']))


def esc(s):
    return html.escape(str(s), quote=True)


class Lang:
    """The language a page is being written in; T(ar, en) picks the text."""
    code = 'ar'

    @classmethod
    def rtl(cls):
        return cls.code == 'ar'


def T(ar, en):
    return ar if Lang.code == 'ar' else en


def num(n):
    """Digits as the app shows them (Western digits in both languages, like the plugin)."""
    return str(n)


# ---------------------------------------------------------------- page frames

FONT_FACE = ("@font-face{font-family:'Alexandria';font-style:normal;font-weight:400 700;font-display:swap;src:url(%(a)sfonts/alexandria/alexandria-arabic.woff2) format('woff2');"
             "unicode-range:U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC}"
             "@font-face{font-family:'Alexandria';font-style:normal;font-weight:400 700;font-display:swap;src:url(%(a)sfonts/alexandria/alexandria-latin.woff2) format('woff2');"
             "unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}")


class Build:
    assets = '../../../workforce-one/assets/'   # from docs/mockups/<feature>/
    kit = '../_kit/'
    out = ROOT


def css(name, plugin=True):
    """A stylesheet link, -rtl for Arabic like WordPress does."""
    base = (Build.assets + 'css/') if plugin else ''
    if Lang.rtl():
        name = name.replace('.css', '-rtl.css')
    return '<link rel="stylesheet" href="%s%s">' % (base, name)


APP_NAV_EMP = [('dashboard', 'home', ('الرئيسية', 'Dashboard')), ('schedule', 'calendar', ('الجدول', 'Schedule')),
               ('time', 'clock', ('الحضور / الانصراف', 'Sign In / Out')), ('vacation', 'leave', ('الإجازات', 'Leave')),
               ('overtime', 'overtime', ('العمل الإضافي', 'Overtime'))]


def app_page(title, body, active='time', css_files=(), feature_css=None, who=('منى عادل', 'Mona Adel'), sub=None,
             scripts='', tabs=None, rail=None, fab=None, header=True):
    """An employee-app page: the real shell (rail on a computer, tab bar on a phone)."""
    name = T(*who)
    ini = ''.join(p[0] for p in name.split()[:2])
    rail = rail or APP_NAV_EMP
    nav = ''.join('<a class="%s" href="#"%s>%s<span class="wfo-rail-label">%s</span></a>' % (
        'active' if v == active else '', ' aria-current="page"' if v == active else '', icon(i), esc(T(*l))) for v, i, l in rail)
    tabs = tabs or [('dashboard', 'home', ('الرئيسية', 'Dashboard')), ('schedule', 'calendar', ('الجدول', 'Schedule')), ('time', None, None),
                    ('vacation', 'leave', ('الإجازات', 'Leave')), ('more', 'more', ('المزيد', 'More'))]
    tabhtml = ''
    for v, i, l in tabs:
        if i is None:
            tabhtml += ('<a class="wfo-tab wfo-tab-clock%s" href="#"><span class="wfo-tab-clock-btn">%s<i class="wfo-clock-dot is-out"></i></span>'
                        '<span class="wfo-tab-label">%s</span></a>') % (' active' if active == 'time' else '', icon('clock', 26, 2), esc(T('الحضور / الانصراف', 'Sign In / Out')))
        else:
            tabhtml += '<a class="wfo-tab%s" href="#"><span class="wfo-tab-icon">%s</span><span class="wfo-tab-label">%s</span></a>' % (
                ' active' if v == active else '', icon(i), esc(T(*l)))
    head = ''
    if header:
        head = ('<header class="wfo-header"><div class="wfo-header-text"><div class="wfo-header-brand">%s</div><h1 class="ews-title">%s</h1>%s</div>'
                '<div class="ews-mobile-top-actions"><div class="ews-notification-bell-wrap"><button type="button" class="ews-notification-bell" aria-label="%s">'
                '<span class="ews-bell-icon" aria-hidden="true">%s</span></button></div><div class="ews-profile-menu"><button type="button" class="ews-profile-menu-trigger">'
                '<span class="ews-profile-menu-initials" aria-hidden="true">%s</span></button></div></div></header>') % (
                    esc(T('شركة النيل للمقاولات · Workforce One', 'Nile Contracting · Workforce One')), esc(title),
                    ('<div class="ews-sub">%s</div>' % esc(sub)) if sub else '', esc(T('الإشعارات', 'Notifications')), icon('bell'), esc(ini))
    sheets = [css('workforce-one.css'), css('app-shell.css')] + [css(c) for c in css_files]
    if feature_css:
        sheets.append(css(feature_css, plugin=False))
    sheets.append('<link rel="stylesheet" href="%smock.css">' % Build.kit)
    return page_doc(title, '\n'.join(sheets), (
        '<body class="wfo-fullscreen mock-app"><main class="wfo-fullscreen-main"><div class="ews-app wfo-app"><div class="wfo-shell">'
        '<aside class="wfo-rail"><a class="wfo-rail-brand" href="#"><span aria-hidden="true">WO</span></a><nav class="ews-nav">%s</nav>'
        '<a class="wfo-rail-logout" href="#">%s<span class="wfo-rail-label">%s</span></a></aside>'
        '<main class="ews-main">%s<div class="wfo-content">%s</div></main></div>'
        '<nav class="wfo-tabbar">%s</nav>%s</div></main>%s</body>') % (
            nav, icon('logout'), esc(T('تسجيل الخروج', 'Log out')), head, body, tabhtml, fab or '', scripts))


def page_doc(title, head, body):
    shell_vars = (".ews-app.wfo-app{--wfo-hero:linear-gradient(135deg,#13235B 0%,#37319A 55%,#5B3FD9 100%);--wfo-hero-start:#13235B;--wfo-hero-mid:#37319A;"
                  "--wfo-hl:#FFC83D;--wfo-hl-ink:#13235B;--wfo-pri:#5B3FD9;--wfo-pri-dark:#4B34B2;--wfo-pri-soft:#EBE8FA;"
                  "--wfo-font:'Alexandria',system-ui,-apple-system,\"Segoe UI\",Tahoma,Arial,sans-serif;--wfo-r-card:20px;--wfo-r-ctl:14px;--wfo-r-sm:12px}")
    return ('<!doctype html>\n<html lang="%s" dir="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>%s</title>\n%s\n<style>%s%s</style></head>\n%s\n</html>\n') % (
                'ar' if Lang.rtl() else 'en', 'rtl' if Lang.rtl() else 'ltr', esc(title), head, FONT_FACE % {'a': Build.assets}, shell_vars, body)


def sheet(title, body, submit, note='', open_=True, icon_name='arrow'):
    """The app's bottom sheet (dialog.wfo-sheet), opened on load like a tap on the yellow button would."""
    return ('<dialog class="wfo-sheet" id="mock-sheet" aria-labelledby="mock-sheet-title"><form class="wfo-sheet-body" onsubmit="return false">'
            '<div class="wfo-sheet-grip" aria-hidden="true"></div><div class="wfo-sheet-head"><h2 id="mock-sheet-title">%s</h2>'
            '<button type="button" class="wfo-sheet-x" aria-label="%s">%s</button></div>%s'
            '<button class="wfo-sheet-submit" type="submit" data-fwd>%s%s</button>%s</form></dialog>%s') % (
                esc(title), esc(T('إغلاق', 'Close')), icon('close', 18, 2.2), body, icon(icon_name, 18, 2.2), esc(submit),
                ('<p class="wfo-sheet-note">%s</p>' % esc(note)) if note else '',
                '<script>document.getElementById("mock-sheet").showModal()</script>' if open_ else '')


def ux_modal(kind, title, msg):
    """The layout's result pop-up (trait-frontend.php ux_modal)."""
    mark = {'error': '!', 'success': '✓', 'warning': '!', 'info': 'i'}[kind]
    return ('<div class="ews-ux-modal is-open mock-open" data-type="%s" role="dialog" aria-modal="true"><div class="ews-ux-modal-card">'
            '<button type="button" class="ews-ux-close" aria-label="%s">×</button><div class="ews-ux-icon">%s</div><h3>%s</h3><p>%s</p>'
            '<div class="ews-ux-actions"><button type="button" class="ews-ux-ok">%s</button></div></div></div>') % (
                kind, esc(T('إغلاق', 'Close')), mark, esc(title), esc(msg), esc(T('حسنًا', 'OK')))


# wp-admin ------------------------------------------------------------------

WP_CORE_MENU = [('dashicons-dashboard', ('لوحة التحكم', 'Dashboard')), ('dashicons-admin-post', ('مقالات', 'Posts')),
                ('dashicons-admin-media', ('الوسائط', 'Media')), ('dashicons-admin-page', ('صفحات', 'Pages'))]
WP_CORE_MENU_AFTER = [('dashicons-admin-appearance', ('المظهر', 'Appearance')), ('dashicons-admin-plugins', ('الإضافات', 'Plugins')),
                      ('dashicons-admin-users', ('الأعضاء', 'Users')), ('dashicons-admin-tools', ('أدوات', 'Tools')),
                      ('dashicons-admin-settings', ('الإعدادات', 'Settings'))]


def admin_page(title, body, submenu, current, css_files=(), feature_css=None, plugin_menu=('Workforce One', 'Workforce One')):
    """A wp-admin page with the WordPress menu, the Workforce One submenu open and `current` selected."""
    def top(dash, label):
        return ('<li class="wp-not-current-submenu menu-top"><a href="#" class="wp-not-current-submenu menu-top"><div class="wp-menu-image dashicons-before %s" aria-hidden="true"><br></div>'
                '<div class="wp-menu-name">%s</div></a></li>') % (dash, esc(T(*label)))
    sub = ''.join('<li%s><a href="#"%s>%s</a></li>' % (
        ' class="current"' if key == current else '', ' class="current" aria-current="page"' if key == current else '', esc(T(*label))) for key, label in submenu)
    menu = ''.join(top(d, l) for d, l in WP_CORE_MENU)
    menu += ('<li class="wp-has-submenu wp-has-current-submenu wp-menu-open menu-top toplevel_page_ews31"><a href="#" class="wp-has-submenu wp-has-current-submenu wp-menu-open menu-top">'
             '<div class="wp-menu-image dashicons-before dashicons-groups" aria-hidden="true"><br></div><div class="wp-menu-name">%s</div></a>'
             '<ul class="wp-submenu wp-submenu-wrap"><li class="wp-submenu-head" aria-hidden="true">%s</li>%s</ul></li>') % (esc(T(*plugin_menu)), esc(T(*plugin_menu)), sub)
    menu += ''.join(top(d, l) for d, l in WP_CORE_MENU_AFTER)
    sheets = ['<link rel="stylesheet" href="%swp-admin-%s.css">' % (Build.kit, 'rtl' if Lang.rtl() else 'ltr'), css('workforce-one-ui.css')]
    sheets += [css(c) for c in css_files]
    if feature_css:
        sheets.append(css(feature_css, plugin=False))
    sheets.append('<link rel="stylesheet" href="%smock.css">' % Build.kit)
    bar = ('<div id="wpadminbar" class="mock-adminbar"><span class="ab-item">%s %s</span><span class="ab-item mock-ab-user">%s</span></div>') % (
        '<span class="dashicons dashicons-wordpress-alt"></span>', esc(T('شركة النيل للمقاولات', 'Nile Contracting')), esc(T('أهلًا، هبة فاروق', 'Howdy, Heba Farouk')))
    doc = ('<body class="wp-admin wp-core-ui js auto-fold admin-bar mock-wpadmin%s"><div id="wpwrap">%s<div id="adminmenumain"><div id="adminmenuback"></div>'
           '<div id="adminmenuwrap"><ul id="adminmenu">%s</ul></div></div><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">'
           '<div class="wrap">%s</div><div class="clear"></div></div></div></div></div></body>') % (' rtl' if Lang.rtl() else '', bar, menu, body)
    return page_doc(title, '\n'.join(sheets), doc).replace('<html ', '<html class="wp-toolbar" ', 1)


# ---------------------------------------------------------------- the gallery

def write(feature, slug, make):
    """Writes <slug>.ar.html and <slug>.en.html."""
    for code in ('ar', 'en'):
        Lang.code = code
        path = os.path.join(Build.out, feature, '%s.%s.html' % (slug, code))
        os.makedirs(os.path.dirname(path), exist_ok=True)
        open(path, 'w').write(make())


def gallery(feature, title, intro, sections):
    """The feature's index.html: every screen in Arabic and English side by side, at its real width.
    sections: [(heading, note, [(slug, label, kind)])] with kind 'phone' | 'desk' | 'a4'."""
    out = []
    for heading, note, items in sections:
        cards = []
        for slug, label, kind in items:
            frames = ''.join(
                '<figure class="g-frame g-%s"><figcaption><a href="%s.%s.html" target="_blank">%s</a></figcaption><div class="g-viewport"><iframe src="%s.%s.html" title="%s" loading="lazy"></iframe></div></figure>' % (
                    kind, slug, code, 'العربية' if code == 'ar' else 'English', slug, code, esc(label)) for code in ('ar', 'en'))
            cards.append('<section class="g-screen"><h3>%s</h3><div class="g-pair">%s</div></section>' % (esc(label), frames))
        out.append('<section class="g-section"><h2>%s</h2>%s%s</section>' % (esc(heading), ('<p class="g-note">%s</p>' % note) if note else '', ''.join(cards)))
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%s</title>'
            '<link rel="stylesheet" href="../_kit/gallery.css"></head><body><header class="g-head"><a class="g-back" href="../index.html">← All mockups</a><h1>%s</h1><div class="g-intro">%s</div></header>%s</body></html>\n') % (
                esc(title), esc(title), intro, ''.join(out))
    open(os.path.join(Build.out, feature, 'index.html'), 'w').write(page)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=ROOT)
    ap.add_argument('--assets', default=None, help='path to workforce-one/assets/ as seen from a screen')
    a = ap.parse_args()
    Build.out = os.path.abspath(a.out)
    if a.assets:
        Build.assets = a.assets
    sys.path.insert(0, KIT)
    import build as module   # the screens import this file as "build", a second copy when run as a script
    module.Build.out, module.Build.assets = Build.out, Build.assets
    import screens_corrections, screens_workers, screens_office
    screens_corrections.build()
    screens_workers.build()
    screens_office.build()
    # the start page: _kit/home.html (written without <html>/<head>, as the Artifact page is published)
    home = open(os.path.join(KIT, 'home.html')).read().replace('url(assets/', 'url(' + Build.assets.replace('../../../', '../../', 1))
    open(os.path.join(Build.out, 'index.html'), 'w').write('<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">\n'
                                                         + home.replace('</style>', '</style></head><body>', 1) + '</body></html>\n')


if __name__ == '__main__':
    main()
