"""Occasions (owner request and decisions 2026-11-09, design docs/tasks/occasions.md; Step 1 mockups).

A switch in Feature Configuration (off by default). The administrator turns one company occasion on (Valentine's Day, Ramadan, Eid
al-Fitr, Eid al-Adha, New Year, Halloween, National day (Egypt), the company's birthday) and chooses how it shows: in the top bar and
the background, or falling over the whole screen for a few seconds. A standing switch celebrates each employee on their own birthday
(only they see it), with a list of people whose birthday everyone sees decorated. The kiosk shows the company occasion only; the login
page too. The layer never takes a tap and never stays over Sign In / Out or the QR.

occasions.css next to the screens is what Step 2 would ship (assets/css/occasions.css); it is written by this file (the motifs are
inline SVG built here) and uses logical properties, so its -rtl copy is identical.
"""
import os
from urllib.parse import quote
from build import T, esc, icon, app_page, admin_page, page_doc, css, write, gallery, Build
import build as _build

_build.ICONS.setdefault('sparkle', '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>')
_build.ICONS.setdefault('eye-off', '<path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c7 0 10 7 10 7a17 17 0 0 1-3.2 4.2"/><path d="M6.6 6.6A17 17 0 0 0 2 12s3 7 10 7a10 10 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>')
_build.ICONS.setdefault('gift', '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v9H5v-9"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>')

F = 'occasions'
CSS = 'occasions.css'
WHO = ('عمر خالد', 'Omar Khaled')


# ---------------------------------------------------------------- the motifs (24 × 24 SVG, filled)

def svg(body):
    return 'url("data:image/svg+xml,%s")' % quote('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">%s</svg>' % body, safe='')


HEART = '<path fill="%s" d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 2.9 4.5 6.6 4.5c2.2 0 3.6 1.2 5.4 3.2 1.8-2 3.2-3.2 5.4-3.2 3.7 0 5.7 3.9 4.2 7.3C19.5 16.4 12 21 12 21z"/>'
CRESCENT = '<path fill="%s" d="M15.5 3.2A9 9 0 1 0 20.8 16 7.2 7.2 0 0 1 15.5 3.2z"/>'
STAR = '<path fill="%s" d="M12 2.5l2.6 6.1 6.6.5-5 4.4 1.5 6.5L12 16.6 6.3 20l1.5-6.5-5-4.4 6.6-.5z"/>'
LANTERN = ('<path fill="%s" d="M10 2h4v2h-4z"/><path fill="%s" d="M8 5h8l1.5 3H6.5z"/><path fill="%s" d="M7 8.5h10l-1 9H8z"/>'
           '<path fill="#fff" fill-opacity=".55" d="M10.2 10h3.6l-.4 6h-2.8z"/><path fill="%s" d="M8 18h8l-1.5 2.5h-5z"/>')
CONFETTI = '<rect fill="%s" x="9" y="4" width="6" height="16" rx="2" transform="rotate(28 12 12)"/>'
SPARK = '<path fill="%s" d="M12 1.5l2.2 8.3 8.3 2.2-8.3 2.2L12 22.5l-2.2-8.3L1.5 12l8.3-2.2z"/>'
BALLOON = '<ellipse fill="%s" cx="12" cy="9" rx="6.5" ry="7.5"/><path fill="%s" d="M10.8 16.2h2.4l-.6 1.6h-1.2z"/><path fill="none" stroke="%s" stroke-width=".8" d="M12 17.8c-1.2 1.6 1.2 2.6 0 4.2"/>'
PUMPKIN = ('<path fill="%s" d="M12 7.5c-1.5-.9-3.6-.9-5 0C4.6 9 4 11.4 4 13.6c0 3.6 2.6 6.4 5.5 6.4.9 0 1.7-.3 2.5-.8.8.5 1.6.8 2.5.8 2.9 0 5.5-2.8 5.5-6.4 0-2.2-.6-4.6-3-6.1-1.4-.9-3.5-.9-5 0z"/>'
           '<path fill="%s" d="M11.2 3.5h1.8l-.3 4.2h-1.2z"/><path fill="#2B1B3D" fill-opacity=".75" d="M8 12l1.6-1.6L11 12zM13 12l1.4-1.6L16 12zM8.5 15c1.2 1.5 5.8 1.5 7 0l-1 2.2c-1.6.8-3.4.8-5 0z"/>')
BAT = '<path fill="%s" d="M12 9.5c.6-1.3 1.4-1.8 1.4-1.8l.2 1.4c1.6-.2 3.6.6 5.4 2.4-1.4-.2-2.2.4-2.4 1.6-.9-.9-2-1-2.6-.3-.4-.6-1.2-.8-2-.4-.8-.4-1.6-.2-2 .4-.6-.7-1.7-.6-2.6.3-.2-1.2-1-1.8-2.4-1.6 1.8-1.8 3.8-2.6 5.4-2.4l.2-1.4s.8.5 1.4 1.8z"/>'
RIBBON = '<rect fill="%s" x="10" y="0" width="4" height="8"/><rect fill="%s" x="10" y="8" width="4" height="8"/><rect fill="%s" x="10" y="16" width="4" height="8"/>'

# key: (label ar, en), (greeting ar, en), palette [ink, a, b, c], [motif 1, 2, 3], kind
OCCASIONS = {
    'valentine': (('عيد الحب', "Valentine's Day"), ('عيد حب سعيد', "Happy Valentine's Day"), ['#7A1030', '#E8436A', '#FF8FAB', '#FFD1DC'],
                  [HEART % '#E8436A', HEART % '#FF8FAB', SPARK % '#FFD1DC'], 'hearts'),
    'ramadan': (('رمضان', 'Ramadan'), ('رمضان كريم', 'Ramadan Kareem'), ['#1B2A5E', '#F5C451', '#2E8B8B', '#FFE7A3'],
                [LANTERN % ('#C99A2E', '#F5C451', '#F5C451', '#C99A2E'), CRESCENT % '#F5C451', STAR % '#FFE7A3'], 'lanterns'),
    'eid_fitr': (('عيد الفطر', 'Eid al-Fitr'), ('عيد فطر مبارك', 'Eid Mubarak'), ['#0F5132', '#2BA36B', '#F5C451', '#BFF0D4'],
                 [CRESCENT % '#F5C451', STAR % '#2BA36B', CONFETTI % '#BFF0D4'], 'stars'),
    'eid_adha': (('عيد الأضحى', 'Eid al-Adha'), ('عيد أضحى مبارك', 'Eid al-Adha Mubarak'), ['#5A3410', '#C8792A', '#F5C451', '#3C8D5A'],
                 [CRESCENT % '#F5C451', STAR % '#C8792A', STAR % '#3C8D5A'], 'stars'),
    'new_year': (('رأس السنة', 'New Year'), ('سنة سعيدة', 'Happy New Year'), ['#3B1F5C', '#F5C451', '#D6338A', '#C9D3E0'],
                 [CONFETTI % '#F5C451', CONFETTI % '#D6338A', SPARK % '#C9D3E0'], 'confetti'),
    'halloween': (('الهالوين', 'Halloween'), ('هالوين سعيد', 'Happy Halloween'), ['#2B1B3D', '#F2761B', '#6A2C91', '#FFB36B'],
                  [PUMPKIN % ('#F2761B', '#3C8D5A'), BAT % '#2B1B3D', STAR % '#FFB36B'], 'pumpkins'),
    'national': (('عيد وطني · مصر', 'National day · Egypt'), ('كل سنة ومصر بخير', 'Happy National Day'), ['#1A1A1A', '#CE1126', '#FFFFFF', '#1A1A1A'],
                 [RIBBON % ('#CE1126', '#FFFFFF', '#1A1A1A'), SPARK % '#C9A227', CONFETTI % '#CE1126'], 'ribbons'),
    'company_birthday': (('عيد ميلاد الشركة', "Company's birthday"), ('١٢ سنة مع بعض', '12 years together'), ['#13235B', '#5B3FD9', '#FFC83D', '#2BA36B'],
                         [BALLOON % ('#5B3FD9', '#5B3FD9', '#13235B'), BALLOON % ('#FFC83D', '#FFC83D', '#13235B'), CONFETTI % '#2BA36B'], 'balloons'),
    'birthday': (('عيد ميلاد الموظف', "Employee's birthday"), ('كل سنة وانت طيب يا عمر!', 'Happy birthday, Omar!'), ['#7A2E00', '#FF6B6B', '#FFC83D', '#4D96FF'],
                 [BALLOON % ('#FF6B6B', '#FF6B6B', '#7A2E00'), BALLOON % ('#4D96FF', '#4D96FF', '#7A2E00'), CONFETTI % '#FFC83D'], 'balloons'),
}
COMPANY = [k for k in OCCASIONS if k != 'birthday']

# 14 particles: inline position, size, delay, length (the same for every occasion; phones keep the odd ones)
SPOTS = [(4, 18, 0, 7.5), (12, 26, 1.2, 8.5), (19, 16, .4, 6.8), (27, 22, 2, 8), (34, 30, .8, 9), (42, 18, 1.6, 7.2), (49, 24, .2, 8.4),
         (57, 16, 1.4, 7), (64, 28, .6, 8.8), (71, 20, 2.2, 7.6), (78, 26, 1, 8.2), (85, 18, 1.8, 7.4), (91, 24, .3, 8.6), (97, 16, 1.1, 7.1)]


def tile(mot, opacity):
    """A 96 × 96 tile of the three motifs, for the header band and the page background."""
    g = ''.join('<g transform="translate(%d %d) scale(%.2f) rotate(%d 12 12)">%s</g>' % (x, y, sc, r, mot[i % 3]) for i, (x, y, sc, r) in enumerate(
        [(6, 8, .75, -12), (52, 4, .6, 18), (28, 40, .7, 8), (72, 46, .8, -20), (8, 66, .6, 24), (50, 72, .7, -6)]))
    return svg('<g opacity="%.2f">%s</g>' % (opacity, g)).replace('viewBox%3D%220%200%2024%2024%22', 'viewBox%3D%220%200%2096%2096%22')


def stylesheet():
    """assets/css/occasions.css as Step 2 would ship it."""
    out = ['/* Occasions (docs/tasks/occasions.md): decorations for the app, the login page and the kiosk. Pure CSS; the motifs are inline SVG.\n'
           '   "bg": particles drift in the top bar for a few seconds then rest, the page background gets a faint pattern; "full": they fall once\n'
           '   over the screen under the tab bar, then are gone. Never a tap (pointer-events:none). Only transform and opacity move; nothing\n'
           '   flashes; reduced motion = still. Logical properties: the -rtl copy is identical. */',
           '.wfo-occ{position:absolute;inset:0;overflow:hidden;pointer-events:none;z-index:-1}',
           '.wfo-occ i{position:absolute;inset-block-start:-34px;inset-inline-start:var(--x);inline-size:var(--s);block-size:var(--s);'
           'background:var(--m) center/contain no-repeat;opacity:.6;animation:wfo-occ-drift var(--t) ease-in-out var(--d) 1 both}',
           '.wfo-occ i:nth-child(3n+1){--m:var(--wfo-occ-m1)}.wfo-occ i:nth-child(3n+2){--m:var(--wfo-occ-m2)}.wfo-occ i:nth-child(3n){--m:var(--wfo-occ-m3)}',
           '@keyframes wfo-occ-drift{0%{transform:translate3d(0,0,0) rotate(0);opacity:0}20%{opacity:.7}'
           '100%{transform:translate3d(0,calc(var(--rest) * 1px),0) rotate(20deg);opacity:.55}}',
           # the top bar: the layer behind its text and buttons, the motifs over the brand colours, a ribbon at its foot
           '.wfo-occ-on .wfo-header{position:relative;isolation:isolate;overflow:hidden;background-image:var(--wfo-occ-tile-head),var(--wfo-hero);background-size:110px,auto}',
           '.wfo-occ-on .wfo-header::after{content:"";position:absolute;inset-inline:0;inset-block-end:0;block-size:5px;z-index:-1;'
           'background:linear-gradient(90deg,var(--wfo-occ-a),var(--wfo-occ-b),var(--wfo-occ-c),var(--wfo-occ-a))}',
           # the page background: a faint pattern the cards sit on
           '.wfo-occ-on .ews-main{background-image:var(--wfo-occ-tile-page);background-size:150px}',
           # full screen: fixed, under the tab bar (9990), once, then gone
           '.wfo-occ.is-full{position:fixed;z-index:9980}',
           '.wfo-occ.is-full i{opacity:.95;animation:wfo-occ-fall var(--t) cubic-bezier(.3,.1,.6,1) var(--d) 1 both}',
           '@keyframes wfo-occ-fall{0%{transform:translate3d(0,0,0) rotate(0);opacity:0}8%{opacity:.95}85%{opacity:.9}'
           '100%{transform:translate3d(30px,110vh,0) rotate(220deg);opacity:0}}',
           # the greeting at the top of the content
           '.wfo-occ-greet{display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:10px 16px;border-radius:999px;'
           'background:#fff;color:var(--wfo-occ-ink);font-weight:700;font-size:14px;box-shadow:inset 0 0 0 2px var(--wfo-occ-c),0 6px 16px rgba(16,24,40,.06)}',
           '.wfo-occ-greet::before{content:"";inline-size:28px;block-size:28px;flex:none;background:var(--wfo-occ-m1) center/contain no-repeat}',
           '.wfo-occ-greet small{margin-inline-start:auto;font-weight:500;color:#667085;font-size:12px}',
           # the login page and the kiosk: the whole background, behind the card
           '.wfo-login.wfo-occ-on{position:relative;isolation:isolate;overflow:hidden;background-image:var(--wfo-occ-tile-page)}',
           '.k-occ{isolation:isolate;background-image:var(--wfo-occ-tile-head),var(--wfo-hero);background-size:130px,auto}.k-occ .wfo-occ{position:fixed}',
           '.k-occ .wfo-occ i{animation:wfo-occ-kiosk calc(var(--t) * 2.4) linear calc(var(--d) * -7) infinite;opacity:.6}',
           '@keyframes wfo-occ-kiosk{0%{transform:translate3d(0,0,0) rotate(0)}100%{transform:translate3d(24px,110vh,0) rotate(160deg)}}',
           # phones: half the particles
           '@media (max-width:600px){.wfo-occ i:nth-child(even){display:none}}',
           # reduced motion: still, and nothing falls over the screen
           '@media (prefers-reduced-motion:reduce){.wfo-occ i{animation:none!important;transform:translate3d(0,calc(var(--rest) * 1px),0) rotate(18deg);opacity:.55}'
           '.wfo-occ.is-full{display:none}}',
           ]
    for n, (x, sz, d, t) in enumerate(SPOTS, 1):
        out.append('.wfo-occ i:nth-child(%d){--x:%d%%;--s:%dpx;--d:%.1fs;--t:%.1fs;--rest:%d}' % (n, x, sz, d, t, 46 + (n * 37) % 70))
    out.append('.wfo-login .wfo-occ i,.k-occ .wfo-occ i{--rest:400}')
    out.append('/* the palettes and motifs (Step 2: printed inline by Occasions::cssVars() from the catalog, for the one occasion on) */')
    for k, (_, _, pal, mot, _) in OCCASIONS.items():
        out.append('.wfo-occ-%s{--wfo-occ-ink:%s;--wfo-occ-a:%s;--wfo-occ-b:%s;--wfo-occ-c:%s;--wfo-occ-m1:%s;--wfo-occ-m2:%s;--wfo-occ-m3:%s;'
                   '--wfo-occ-tile-head:%s;--wfo-occ-tile-page:%s}' % (k, pal[0], pal[1], pal[2], pal[3], svg(mot[0]), svg(mot[1]), svg(mot[2]), tile(mot, .3), tile(mot, .1)))
    return '\n'.join(out) + '\n'


def layer(mode='bg'):
    return '<div class="wfo-occ is-%s" aria-hidden="true">%s</div>' % (mode, '<i></i>' * 14)


def greet(key, sub=None):
    g = T(*OCCASIONS[key][1])
    return '<p class="wfo-occ-greet" role="status">%s%s</p>' % (esc(g), ('<small>%s</small>' % esc(sub)) if sub else '')


def decorate(html_, key, mode='bg'):
    """The app page with the occasion: the class on .ews-app; "bg" puts the layer in the top bar, "full" over the screen."""
    html_ = html_.replace('<div class="ews-app wfo-app">', '<div class="ews-app wfo-app wfo-occ-on wfo-occ-%s">' % key, 1)
    if mode == 'full':
        return html_.replace('<nav class="wfo-tabbar">', layer('full') + '<nav class="wfo-tabbar">', 1)
    return html_.replace('<header class="wfo-header">', '<header class="wfo-header">' + layer('bg'), 1)


def home_body(key, moments=None):
    m = moments or [('cake', T('عيد ميلاد سعيد يا سارة!', 'Happy birthday, Sara!'), T('اتمنالها يوم حلو.', 'Wish her a great day.'))]
    mom = ''.join('<div class="wfo-moment"><span class="wfo-moment-icon">%s</span><div><strong>%s</strong><p>%s</p></div></div>' % (icon('gift', 20), esc(a), esc(b)) for _, a, b in m)
    return ('%s<div class="wfo-home is-employee"><a class="wfo-apv-cta" href="#"><span>+ %s</span><span aria-hidden="true">›</span></a>'
            '<section class="wfo-home-today"><div class="wfo-home-today-head"><div><span class="wfo-muted">%s</span><h2 class="wfo-home-status">%s</h2></div></div>'
            '<div class="wfo-home-times"><div><span>%s</span><b>08:52</b></div><div><span>%s</span><b>%s</b></div><div><span>%s</span><b>%s</b></div></div>'
            '<a class="wfo-home-action" href="#">%s%s</a></section>'
            '<section class="wfo-card wfo-home-moments"><div class="wfo-card-head"><h3>%s</h3><p class="wfo-muted">%s</p></div><div class="wfo-moments">%s</div></section>'
            '</div>') % (
                greet(key) if key else '', esc(T('طلب جديد', 'New request')), esc(T('النهارده', 'Today')), esc(T('في المكتب', 'In the office')),
                esc(T('الحضور', 'Sign In')), esc(T('الانصراف', 'Sign Out')), esc(T('لم يسجل', 'Not recorded')), esc(T('مكان العمل', 'Work Location')),
                esc(T('المقر الرئيسي', 'Head office')), icon('clock', 20), esc(T('عرض الحضور', 'View Attendance')),
                esc(T('لحظات اليوم', "Today's Moments")), esc(T('احتفال صغير للفريق.', 'A little celebration for the team.')), mom)


def dash(key, mode='bg'):
    def make():
        return decorate(app_page(T('الرئيسية', 'Dashboard'), home_body(key), active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), key, mode)
    return make


def dash_off():
    return app_page(T('الرئيسية', 'Dashboard'), home_body(None), active='dashboard', css_files=['app-home.css'], who=WHO)


def birthday_me():
    return decorate(app_page(T('الرئيسية', 'Dashboard'), home_body('birthday'), active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'birthday')


def birthday_everyone():
    """A colleague on the day of someone the administrator picked: everyone sees it."""
    def make():
        body = home_body(None, [('cake', T('عيد ميلاد سعيد يا م. خالد!', 'Happy birthday, Eng. Khaled!'), T('المدير العام', 'General Manager'))])
        body = ('<p class="wfo-occ-greet" role="status">%s<small>%s</small></p>' % (esc(T('كل سنة وانت طيب يا م. خالد!', 'Happy birthday, Eng. Khaled!')),
                                                                                  esc(T('عيد ميلاد المدير العام', "Our General Manager's birthday")))) + body
        return decorate(app_page(T('الرئيسية', 'Dashboard'), body, active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=('منى عادل', 'Mona Adel')), 'birthday')
    return make


def time_view():
    body = ('%s<section class="wfo-home-today"><div class="wfo-home-today-head"><div><span class="wfo-muted">%s</span><h2 class="wfo-home-status">%s</h2></div></div>'
            '<div class="wfo-home-times"><div><span>%s</span><b>—</b></div><div><span>%s</span><b>%s</b></div></div>'
            '<a class="wfo-home-action" href="#">%s%s</a></section>') % (
                greet('ramadan'), esc(T('الثلاثاء ١٨ فبراير', 'Tuesday 18 February')), esc(T('ما سجلتش حضور لسه', 'Not signed in yet')),
                esc(T('الحضور', 'Sign In')), esc(T('مكان العمل', 'Work Location')), esc(T('المقر الرئيسي', 'Head office')), icon('login', 20, 2), esc(T('تسجيل الحضور', 'Sign In')))
    return decorate(app_page(T('الحضور / الانصراف', 'Sign In / Out'), body, active='time', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'ramadan')


def menu_view():
    """The profile menu open, with Hide decorations."""
    menu = ('<div class="mock-occ-menu" role="menu"><a href="#">%s%s</a><a href="#">%s%s</a><a href="#" class="is-occ">%s%s<small>%s</small></a><a href="#">%s%s</a></div>') % (
        icon('user', 18), esc(T('بروفايلي', 'My Profile')), icon('settings', 18), esc(T('المظهر', 'Appearance')),
        icon('eye-off', 18), esc(T('إخفاء الزينة', 'Hide decorations')), esc(T('لحد المناسبة الجاية', 'until the next occasion')), icon('logout', 18), esc(T('تسجيل الخروج', 'Log out')))
    menu += ('<style>.mock-occ-menu{position:fixed;inset-block-start:70px;inset-inline-end:14px;z-index:50;background:#fff;border-radius:16px;padding:6px;'
             'box-shadow:0 18px 40px rgba(16,24,40,.18);display:grid;min-width:230px}.mock-occ-menu a{display:flex;align-items:center;gap:10px;padding:11px 12px;'
             'border-radius:10px;color:#1d2939;text-decoration:none;font-size:14px;flex-wrap:wrap}.mock-occ-menu a.is-occ{background:#F4F1FF}'
             '.mock-occ-menu small{flex-basis:100%;padding-inline-start:28px;color:#667085;font-size:12px}</style>')
    return decorate(app_page(T('الرئيسية', 'Dashboard'), home_body('new_year') + menu, active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'new_year')


BODY_VARS = ("<style>body{--wfo-hero:linear-gradient(135deg,#13235B 0%,#37319A 55%,#5B3FD9 100%);--wfo-hero-start:#13235B;--wfo-hl:#FFC83D;--wfo-hl-ink:#13235B;"
             "--wfo-pri:#5B3FD9;--wfo-pri-dark:#4B34B2;--wfo-pri-soft:#EBE8FA;--wfo-font:'Alexandria',system-ui,sans-serif;--wfo-r-card:20px;--wfo-r-ctl:14px;--wfo-r-sm:12px}</style>")


def login():
    sheets = BODY_VARS + '\n'.join([css('workforce-one.css'), css('app-login.css'), css(CSS, plugin=False), '<link rel="stylesheet" href="%smock.css">' % Build.kit])
    body = ('<body class="mock-app"><div class="ews-login-wrap wfo-login is-bg-gradient wfo-occ-on wfo-occ-eid_fitr">%s<div class="wfo-login-inner">'
            '<div class="wfo-login-brand"><span class="wfo-login-mark">NC</span><strong class="ews-login-logo">Workforce One</strong><span class="wfo-login-line">%s</span></div>'
            '<div class="ews-login-card wfo-login-card">%s<h1>%s</h1><p class="ews-login-sub">%s</p>'
            '<form onsubmit="return false"><p><label>%s</label><input type="text" class="input" value="omar.k"></p><p><label>%s</label><input type="password" class="input" value="xxxxxxxx"></p>'
            '<p class="login-submit"><button class="button button-primary ews-login-btn wfo-login-submit">%s</button></p></form></div></div></div></body>') % (
                layer(), esc(T('شركة النيل للمقاولات', 'Nile Contracting')), greet('eid_fitr'), esc(T('أهلًا بيك تاني', 'Welcome back')),
                esc(T('سجّل دخول بحساب الشغل', 'Sign in with your work account')), esc(T('اسم المستخدم', 'Username')), esc(T('كلمة المرور', 'Password')), esc(T('دخول', 'Log In')))
    return page_doc(T('تسجيل الدخول', 'Log in'), sheets, body)


def kiosk():
    sheets = BODY_VARS + '\n'.join(['<link rel="stylesheet" href="%scss/kiosk.css">' % Build.assets, css(CSS, plugin=False)])
    cells = ''.join('<rect x="%d" y="%d" width="1" height="1"/>' % (x, y) for y in range(25) for x in range(25) if (x * 7 + y * 13 + x * y) % 3 == 0)
    finder = ''.join('<path d="M%d %dh7v7h-7z" fill="none" stroke="#000" stroke-width="1"/><rect x="%d" y="%d" width="3" height="3"/>' % (a, b, a + 2, b + 2) for a, b in [(0.5, 0.5), (17.5, 0.5), (0.5, 17.5)])
    qr = '<svg viewBox="-1 -1 27 27" width="100%%" height="100%%" style="background:#fff">%s%s</svg>' % (cells, finder)
    body = ('<body class="wfo-occ-on wfo-occ-ramadan k-occ"><main class="k">%s<section class="k-info"><div class="k-brand"><span aria-hidden="true">NC</span><div><small>Workforce One</small><strong>%s</strong></div></div>'
            '<div class="k-clock"><b>08:41</b><span>%s</span></div><ol class="k-steps"><li>%s</li><li>%s</li><li>%s</li></ol></section>'
            '<section class="k-card"><h1>%s</h1><div class="qr">%s</div><div class="k-next"><div class="k-bar"><i style="width:60%%"></i></div><span class="timer">%s</span></div></section></main></body>') % (
                layer(), esc(T('المقر الرئيسي', 'Head office')), esc(T('الثلاثاء، ١٨ فبراير', 'Tuesday, 18 February')),
                esc(T('افتح Workforce One على موبايلك', 'Open Workforce One on your phone')), esc(T('افتح تسجيل الحضور أو طلب التواجد', 'Open Sign In or the presence request')),
                esc(T('وجّه الكاميرا للكود ده', 'Point the camera at this code')), esc(T('امسح للمتابعة', 'Scan to continue')), qr, esc(T('يتغير بعد ١٢ ث', 'Changes in 12s')))
    return page_doc('Kiosk', sheets, body)


# ---------------------------------------------------------------- wp-admin → Feature Configuration → Occasions

ADMIN_SUB = [('dashboard', ('لوحة التحكم', 'Dashboard')), ('employees', ('الموظفين', 'Employees')), ('features', ('إعدادات الميزات', 'Feature Configuration')),
             ('appearance', ('المظهر', 'Appearance')), ('moments', ('لحظات الموظفين', 'Employee Moments'))]


def features(state='on'):
    def make():
        cards = ''
        for k in COMPANY:
            pal = OCCASIONS[k][2]
            sel = (k == 'ramadan')
            cards += ('<label class="occ-pick%s"><input type="radio" name="occ"%s><span class="occ-sw wfo-occ-%s"><i></i><i></i><i></i></span><b>%s</b>'
                      '<span class="occ-dots">%s</span><a href="#" class="occ-prev">%s</a></label>') % (
                          ' is-on' if sel else '', ' checked' if sel else '', k, esc(T(*OCCASIONS[k][0])),
                          ''.join('<em style="background:%s"></em>' % c for c in pal[1:]), esc(T('معاينة', 'Preview')))
        cards += '<label class="occ-pick is-none"><input type="radio" name="occ"><b>%s</b><span class="occ-note">%s</span></label>' % (
            esc(T('ولا مناسبة', 'No occasion')), esc(T('أعياد الميلاد بس، لو شغالة', 'Birthdays only, if on')))
        people = ''.join('<span class="occ-chip">%s<button type="button" aria-label="%s">×</button></span>' % (esc(T(*n)), esc(T('شيل', 'Remove'))) for n in
                         [('م. خالد يوسف · المدير العام', 'Eng. Khaled Youssef · General Manager'), ('هبة فاروق · HR', 'Heba Farouk · HR')])
        err = ''
        if state == 'error':
            err = '<div class="notice notice-error inline"><p>%s</p></div>' % esc(T('آخر يوم للمناسبة لازم يكون النهارده أو بعده.', "The occasion's last day must be today or later."))
        on = state != 'off'
        fields = ''
        if on:
            fields = ('%s<div class="wfo-feature-fields occ-fields"><div class="occ-wide"><span class="wfo-field-label">%s</span><div class="occ-grid">%s</div></div>'
                      '<div class="occ-wide"><span class="wfo-field-label">%s</span><div class="occ-modes wfo-occ-ramadan">'
                      '<label class="occ-mode is-on"><input type="radio" name="mode" checked><b>%s</b><small>%s</small><span class="occ-mini is-bg"></span></label>'
                      '<label class="occ-mode"><input type="radio" name="mode"><b>%s</b><small>%s</small><span class="occ-mini is-full"></span></label></div></div>'
                      '<label><span class="wfo-field-label">%s</span><input type="date" value="%s"><small class="occ-help">%s</small></label>'
                      '<label><span class="wfo-field-label">%s</span><input type="text" value="%s" placeholder="%s"><small class="occ-help">%s</small></label>'
                      '<label><span class="wfo-field-label">%s</span><input type="date" value="2014-03-01"><small class="occ-help">%s</small></label>'
                      '<label class="occ-check"><input type="checkbox" checked> %s<small class="occ-help">%s</small></label>'
                      '</div>'
                      '<div class="wfo-subpanel"><div class="wfo-subpanel-title"><label><input type="checkbox" checked> %s</label></div>'
                      '<p class="wfo-subpanel-desc">%s <a href="#">%s</a></p>'
                      '<span class="wfo-field-label">%s</span><div class="occ-people">%s<input type="text" placeholder="%s"></div>'
                      '<small class="occ-help">%s</small></div>') % (
                          err, esc(T('المناسبة الشغالة (واحدة بس في نفس الوقت)', 'The occasion on now (one at a time)')), cards,
                          esc(T('تظهر إزاي', 'How it shows')),
                          esc(T('الشريط اللي فوق والخلفية', 'Top bar and background')), esc(T('ثواني حركة لما الصفحة تفتح، وبعدها الزينة تفضل هادية ورا المحتوى.', 'A few seconds of movement when a page opens, then the decorations stay quietly behind the content.')),
                          esc(T('تنزل على الشاشة كلها', 'Falling over the whole screen')), esc(T('تنزل على الشاشة مرة لثواني وتختفي. عمرها ما تمنع لمسة ولا تفضل على زرار الحضور.', 'Falls over the screen once for a few seconds, then is gone. It never blocks a tap or stays over Sign In / Out.')),
                          esc(T('آخر يوم (اختياري)', 'Last day (optional)')), '2027-03-10', esc(T('فاضي: لحد ما تقفلها.', 'Empty: until you switch it off.')),
                          esc(T('رسالة التهنئة', 'Greeting')), esc(T('رمضان كريم من أسرة النيل', 'Ramadan Kareem from the Nile family')), esc(T(*OCCASIONS['ramadan'][1])),
                          esc(T('فاضي: التهنئة الجاهزة. ١٢٠ حرف بحد أقصى.', 'Empty: the built-in greeting. 120 characters at most.')),
                          esc(T('تاريخ تأسيس الشركة', "The company's founding date")), esc(T('لـ "١٢ سنة مع بعض" في عيد ميلاد الشركة.', 'For "12 years together" on the company\'s birthday.')),
                          esc(T('تظهر على شاشات الكشك', 'Show on kiosk screens')), esc(T('مناسبة الشركة بس، من غير أسامي.', 'The company occasion only, never names.')),
                          esc(T('احتفل بكل موظف في عيد ميلاده', 'Celebrate each employee on their birthday')),
                          esc(T('في يوم ميلاده هو بس يشوف الزينة و"كل سنة وانت طيب". السنة مش بتظهر. ٤١ من ٥٨ موظف متسجل لهم تاريخ ميلاد في', 'On their day only they see the decorations and "Happy birthday". The year is never shown. 41 of 58 employees have a birthday saved in')),
                          esc(T('لحظات الموظفين', 'Employee Moments')),
                          esc(T('الكل يشوف زينة عيد ميلاد:', "Everyone sees the decorations on the birthday of:")), people, esc(T('ابحث عن موظف…', 'Search an employee…')),
                          esc(T('في يوم ميلادهم الزينة والتهنئة باسمهم تظهر لكل الموظفين (مش في الكشك ولا صفحة الدخول).', 'On their day the decorations and a greeting with their name show to every employee (not on kiosks or the login page).')))
        body = ('<h1 class="wp-heading-inline">%s</h1><div class="wfo-features-shell" style="margin-top:18px"><div class="wfo-feature-section" id="ews-occasions">'
                '<div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>'
                '<label class="wfo-feature-status"><input type="checkbox"%s> %s</label></div>%s</div>'
                '<div class="wfo-savebar"><span style="color:#667085;font-size:13px">%s</span><button class="button button-primary">%s</button></div></div>') % (
                    esc(T('إعدادات الميزات', 'Feature Configuration')), esc(T('المناسبات', 'Occasions')),
                    esc(T('زينة وألوان للمناسبة على تطبيق الموظفين وصفحة الدخول والكشك. إنت اللي بتشغّلها وبتقفلها؛ مافيش حاجة بتشتغل لوحدها.', 'Decorations and colours for an occasion on the employee app, the login page and kiosks. You switch it on and off; nothing starts by itself.')),
                    ' checked' if on else '', esc(T('مفعّلة', 'Enabled')), fields,
                    esc(T('التغييرات بتطبق بعد الحفظ.', 'Changes apply after saving this configuration.')), esc(T('حفظ إعدادات الميزات', 'Save Feature Configuration')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, ADMIN_SUB, 'features', css_files=['admin-features.css'], feature_css='occasions-admin.css')
    return make


def build():
    d = os.path.join(Build.out, F)
    os.makedirs(d, exist_ok=True)
    s = stylesheet()
    for n in ('occasions.css', 'occasions-rtl.css'):
        open(os.path.join(d, n), 'w').write(s)
    for k in COMPANY:
        write(F, 'dash-' + k, dash(k))
    write(F, 'dash-ramadan-full', dash('ramadan', 'full'))
    write(F, 'dash-off', dash_off)
    write(F, 'birthday-me', birthday_me)
    write(F, 'birthday-everyone', birthday_everyone())
    write(F, 'time', time_view)
    write(F, 'menu', menu_view)
    write(F, 'login', login)
    write(F, 'kiosk', kiosk)
    write(F, 'features', features('on'))
    write(F, 'features-off', features('off'))
    write(F, 'features-error', features('error'))
    gallery(F, 'Occasions · المناسبات', (
        '<p>Owner decisions 2026-11-09 (<code>docs/tasks/occasions.md</code>). Off by default; one company occasion at a time, switched on and off by the '
        'administrator, with an optional last day. Reload a screen to see the movement again; with "reduce motion" on, it stays still.</p>'
        '<p>قرارات المالك ٢٠٢٦-١١-٠٩. مقفولة افتراضيًا؛ مناسبة واحدة للشركة في نفس الوقت، الأدمن بيشغلها ويقفلها، وآخر يوم اختياري. اعمل reload للصفحة عشان تشوف الحركة تاني.</p>'), [
        ('1. Feature Configuration · إعدادات الميزات', 'The Occasions card: off, on (Ramadan, top bar and background, a last day, a greeting), and an error.',
         [('features', 'On · مفعّلة', 'desk'), ('features-off', 'Off · مقفولة', 'desk'), ('features-error', 'A last day in the past · آخر يوم فات', 'desk')]),
        ('2. Each occasion on a phone · كل مناسبة على الموبايل', 'Top bar and background: a few seconds of movement, then still. The Sign In / Out button in the tab bar is never covered.',
         [('dash-' + k, OCCASIONS[k][0][1] + ' · ' + OCCASIONS[k][0][0], 'phone') for k in COMPANY] + [('dash-off', 'Off (today) · من غير مناسبة', 'phone')]),
        ('3. Falling over the whole screen · تنزل على الشاشة كلها', 'Falls once for a few seconds under the tab bar, never takes a tap, then is gone (reload to replay).',
         [('dash-ramadan-full', 'Ramadan, full screen · رمضان، الشاشة كلها', 'phone')]),
        ('4. Birthdays · أعياد الميلاد', 'Omar on his birthday (only he sees it); Mona on the birthday of the General Manager, whom the administrator picked (everyone sees it).',
         [('birthday-me', 'My birthday · عيد ميلادي', 'phone'), ('birthday-everyone', 'A picked person · شخص مختار', 'phone')]),
        ('5. Sign In / Out, the menu, the login page · الحضور والقائمة وصفحة الدخول', '',
         [('time', 'Sign In / Out · الحضور', 'phone'), ('menu', 'Hide decorations · إخفاء الزينة', 'phone'), ('login', 'Login page · صفحة الدخول', 'phone')]),
        ('6. Kiosk · الكشك', 'The company occasion only, slow and continuous, behind the QR card; never names.', [('kiosk', 'Kiosk · الكشك', 'desk')]),
    ])
