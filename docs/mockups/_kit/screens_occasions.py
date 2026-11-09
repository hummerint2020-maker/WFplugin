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
           'background:var(--m) center/contain no-repeat;opacity:.9;animation:wfo-occ-drift var(--t) ease-in-out var(--d) 1 both;filter:drop-shadow(0 2px 3px rgba(0,0,0,.18))}',
           '.wfo-occ i:nth-child(3n+1){--m:var(--wfo-occ-m1)}.wfo-occ i:nth-child(3n+2){--m:var(--wfo-occ-m2)}.wfo-occ i:nth-child(3n){--m:var(--wfo-occ-m3)}',
           '@keyframes wfo-occ-drift{0%{transform:translate3d(0,0,0) rotate(0);opacity:0}20%{opacity:.7}'
           '100%{transform:translate3d(0,calc(var(--rest) * 1px),0) rotate(20deg);opacity:.9}}',
           # the top bar: the layer behind its text and buttons, the motifs over the brand colours, a ribbon at its foot
           '.wfo-occ-on .wfo-header{position:relative;isolation:isolate;overflow:hidden;background-image:var(--wfo-occ-tile-head),var(--wfo-hero);background-size:96px,auto}',
           '.wfo-occ-on .wfo-header::after{content:"";position:absolute;inset-inline:0;inset-block-end:0;block-size:5px;z-index:-1;'
           'background:linear-gradient(90deg,var(--wfo-occ-a),var(--wfo-occ-b),var(--wfo-occ-c),var(--wfo-occ-a))}',
           # the page background: a faint pattern the cards sit on
           '.ews-app.wfo-occ-on .ews-main{position:relative;isolation:isolate;background-image:var(--wfo-occ-tile-page);background-size:130px}',
           # the page: big motifs drift down behind the cards and rest between them
           '.wfo-occ.is-page i{--s:calc(var(--sz) * 1.9);opacity:.9;inset-block-start:60px;animation:wfo-occ-page calc(var(--t) * .8) ease-out var(--d) 1 both}',
           '@keyframes wfo-occ-page{0%{transform:translate3d(0,-80px,0) rotate(-20deg) scale(.6);opacity:0}25%{opacity:.95}'
           '100%{transform:translate3d(0,calc(var(--restp) * 1px),0) rotate(12deg) scale(1);opacity:.8}}',
           # a ribbon over the tab bar too (above it, never on its buttons)
           '.wfo-occ-on .wfo-tabbar{box-shadow:0 -4px 0 -1px var(--wfo-occ-a),0 -8px 0 -2px var(--wfo-occ-b),0 -12px 24px rgba(16,24,40,.08)}',
           # full screen: fixed, under the tab bar (9990), once, then gone
           '.wfo-occ.is-full{position:fixed;z-index:9980}',
           '.wfo-occ.is-full i{opacity:.95;animation:wfo-occ-fall var(--t) cubic-bezier(.3,.1,.6,1) var(--d) 1 both}',
           '@keyframes wfo-occ-fall{0%{transform:translate3d(0,0,0) rotate(0);opacity:0}8%{opacity:.95}85%{opacity:.9}'
           '100%{transform:translate3d(30px,110vh,0) rotate(220deg);opacity:0}}',
           # the greeting at the top of the content
           '.wfo-occ-greet{position:relative;overflow:hidden;isolation:isolate;display:flex;align-items:center;gap:14px;margin:0 0 16px;'
           'padding:18px 20px 22px;border-radius:24px;color:#fff;'
           'background:var(--wfo-occ-tile-page) 0 0/96px,radial-gradient(120% 140% at 100% 0%,var(--wfo-occ-a) 0%,transparent 55%),'
           'linear-gradient(135deg,var(--wfo-occ-ink) 0%,var(--wfo-occ-ink) 45%,var(--wfo-occ-a) 160%);'
           'box-shadow:0 14px 30px -12px var(--wfo-occ-ink);animation:wfo-occ-pop .7s cubic-bezier(.2,1.3,.4,1) both}',
           '[dir=rtl] .wfo-occ-greet{background:var(--wfo-occ-tile-page) 0 0/96px,radial-gradient(120% 140% at 0% 0%,var(--wfo-occ-a) 0%,transparent 55%),'
           'linear-gradient(225deg,var(--wfo-occ-ink) 0%,var(--wfo-occ-ink) 45%,var(--wfo-occ-a) 160%)}',
           '@keyframes wfo-occ-pop{0%{transform:translateY(8px) scale(.96);opacity:0}100%{transform:none;opacity:1}}',
           # the ribbon at its foot
           '.wfo-occ-greet::after{content:"";position:absolute;inset-inline:0;inset-block-end:0;block-size:6px;'
           'background:linear-gradient(90deg,var(--wfo-occ-a),var(--wfo-occ-b),var(--wfo-occ-c),var(--wfo-occ-a))}',
           # a light sweep across it once
           '.wfo-occ-greet::before{content:"";position:absolute;inset-block:0;inset-inline-start:-40%;inline-size:30%;z-index:-1;'
           'background:linear-gradient(100deg,transparent,rgba(255,255,255,.22),transparent);animation:wfo-occ-shine 1.6s ease-out .5s 1 both}',
           '@keyframes wfo-occ-shine{0%{transform:translateX(0)}100%{transform:translateX(480%)}}',
           '[dir=rtl] .wfo-occ-greet::before{animation-name:wfo-occ-shine-rtl}@keyframes wfo-occ-shine-rtl{0%{transform:translateX(0)}100%{transform:translateX(-480%)}}',
           '.wfo-occ-greet-text{display:grid;gap:4px;min-width:0;flex:1}',
           '.wfo-occ-greet-text b{font-size:22px;line-height:1.25;font-weight:800;letter-spacing:-.01em;text-shadow:0 1px 2px rgba(0,0,0,.25)}',
           '.wfo-occ-greet-text small{font-size:13px;font-weight:500;color:rgba(255,255,255,.85)}',
           # the motif in a soft halo
           '.wfo-occ-greet-art{position:relative;flex:none;inline-size:72px;block-size:72px;border-radius:50%;'
           'background:var(--wfo-occ-m1) center/46px no-repeat,radial-gradient(circle,rgba(255,255,255,.95) 0%,rgba(255,255,255,.75) 55%,rgba(255,255,255,0) 72%);'
           'animation:wfo-occ-bob 2.4s ease-in-out .7s 2 both}',
           '.wfo-occ-greet-art::after{content:"";position:absolute;inline-size:22px;block-size:22px;inset-block-start:-4px;inset-inline-end:-6px;'
           'background:var(--wfo-occ-m3) center/contain no-repeat}',
           '@keyframes wfo-occ-bob{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(-5px) rotate(-6deg)}}',
           # the login page and the kiosk: the whole background, behind the card
           '.wfo-login.wfo-occ-on{position:relative;isolation:isolate;overflow:hidden;background-image:var(--wfo-occ-tile-page)}',
           '.k-occ{isolation:isolate;background-image:var(--wfo-occ-tile-head),var(--wfo-hero);background-size:130px,auto}.k-occ .wfo-occ{position:fixed}',
           '.k-occ .wfo-occ i{animation:wfo-occ-kiosk calc(var(--t) * 2.4) linear calc(var(--d) * -7) infinite;opacity:.6}',
           '@keyframes wfo-occ-kiosk{0%{transform:translate3d(0,0,0) rotate(0)}100%{transform:translate3d(24px,110vh,0) rotate(160deg)}}',
           # the birthday moment: a cake, the candle blown out, "Happy Birthday" (once a day, ~5 s, never takes a tap)
           '.wfo-bday{position:fixed;inset:0;z-index:9985;pointer-events:none;display:grid;place-items:center;align-content:center;gap:6px;'
           'background:radial-gradient(circle at 50% 42%,rgba(255,255,255,.96) 0%,rgba(255,244,236,.94) 45%,rgba(255,214,200,.9) 100%);'
           'animation:wfo-bday-in .4s ease-out both,wfo-bday-out .6s ease-in 5.2s both}',
           '@keyframes wfo-bday-in{from{opacity:0}to{opacity:1}}@keyframes wfo-bday-out{to{opacity:0;visibility:hidden}}',
           '.wfo-bday-cake{inline-size:min(68vw,270px);animation:wfo-bday-pop .7s cubic-bezier(.2,1.5,.4,1) .1s both}',
           '@keyframes wfo-bday-pop{from{transform:translateY(40px) scale(.6);opacity:0}to{transform:none;opacity:1}}',
           '.wfo-bday-flame{transform-box:fill-box;transform-origin:50% 100%;animation:wfo-bday-flicker .18s ease-in-out infinite alternate,wfo-bday-blow .35s ease-in 2.3s forwards}',
           '@keyframes wfo-bday-flicker{from{transform:scale(1,1) rotate(-3deg)}to{transform:scale(.92,1.08) rotate(3deg)}}',
           '@keyframes wfo-bday-blow{0%{transform:scale(1.1,.8) skewX(-25deg);opacity:1}100%{transform:scale(0,0);opacity:0}}',
           '.wfo-bday-glow{transform-box:fill-box;transform-origin:50% 50%;animation:wfo-bday-glow 1s ease-in-out infinite alternate,wfo-bday-blow .35s ease-in 2.3s forwards}',
           '@keyframes wfo-bday-glow{from{opacity:.55}to{opacity:.9}}',
           '.wfo-bday-smoke circle{opacity:0;transform-box:fill-box;transform-origin:50% 50%;animation:wfo-bday-smoke 1.6s ease-out 2.55s both}',
           '.wfo-bday-smoke circle:nth-child(2){animation-delay:2.7s}.wfo-bday-smoke circle:nth-child(3){animation-delay:2.85s}',
           '@keyframes wfo-bday-smoke{0%{opacity:.7;transform:translate(0,0) scale(.6)}100%{opacity:0;transform:translate(6px,-34px) scale(1.8)}}',
           '.wfo-bday-title{display:flex;gap:.06em;justify-content:center;flex-wrap:wrap;direction:ltr;margin:6px 0 0;font-size:clamp(26px,8.6vw,42px);font-weight:900;'
           'letter-spacing:.02em;color:#E8436A;text-shadow:0 3px 0 #FFC83D,0 6px 18px rgba(232,67,106,.35)}',
           '.wfo-bday-title span{display:inline-block;opacity:0;animation:wfo-bday-letter .45s cubic-bezier(.2,1.6,.4,1) both}',
           '.wfo-bday-title .sp{inline-size:.35em}.wfo-bday-title .w{display:inline-flex;white-space:nowrap}',
           '@keyframes wfo-bday-letter{from{opacity:0;transform:translateY(24px) scale(.4) rotate(-12deg)}to{opacity:1;transform:none}}',
           '.wfo-bday-name{margin:0;font-size:18px;font-weight:700;color:#7A2E00;opacity:0;animation:wfo-bday-letter .5s ease-out 3.6s both}',
           '.wfo-bday-burst i{position:absolute;inset-block-start:46%;inset-inline-start:50%;inline-size:10px;block-size:16px;border-radius:3px;opacity:0;'
           'animation:wfo-bday-burst 1.6s cubic-bezier(.1,.7,.3,1) 2.7s both}',
           '@keyframes wfo-bday-burst{0%{opacity:0;transform:translate(0,0) rotate(0)}6%{opacity:1}100%{opacity:0;transform:translate(var(--bx),var(--by)) rotate(var(--br))}}',
           '@media (prefers-reduced-motion:reduce){.wfo-bday{animation:wfo-bday-out .3s ease-in 3s both}.wfo-bday *{animation:none!important;opacity:1!important}'
           '.wfo-bday-flame,.wfo-bday-glow,.wfo-bday-burst,.wfo-bday-smoke{display:none}}',
           # the Halloween moment: night, a moon, the pumpkin lights up, bats fly out, "HAPPY HALLOWEEN" (once a day, ~5 s, never a tap)
           '.wfo-hw{position:fixed;inset:0;z-index:9985;pointer-events:none;display:grid;place-items:center;align-content:center;gap:4px;overflow:hidden;'
           'background:radial-gradient(circle at 50% 30%,#4A2A6E 0%,#2B1B3D 45%,#140B1F 100%);animation:wfo-bday-in .5s ease-out both,wfo-bday-out .7s ease-in 5.3s both}',
           '.wfo-hw-moon{position:absolute;inset-block-start:9%;inset-inline-end:12%;inline-size:110px;block-size:110px;border-radius:50%;'
           'background:radial-gradient(circle at 38% 38%,#FFF8D6 0%,#F5E7A8 60%,#E0CC7E 100%);box-shadow:0 0 60px 18px rgba(245,231,168,.35);'
           'animation:wfo-bday-in 1s ease-out both}',
           '.wfo-hw-pumpkin{inline-size:min(60vw,230px);animation:wfo-bday-pop .8s cubic-bezier(.2,1.5,.4,1) .2s both}',
           '.wfo-hw-face{fill:#2B1B3D;animation:wfo-hw-light .9s ease-in 1.1s forwards}',
           '@keyframes wfo-hw-light{0%{fill:#2B1B3D}40%{fill:#FFB020}55%{fill:#7a3b00}100%{fill:#FFD34D}}',
           '.wfo-hw-glow{opacity:0;transform-box:fill-box;transform-origin:50% 50%;animation:wfo-hw-glow 1.1s ease-in-out 1.6s infinite alternate}',
           '@keyframes wfo-hw-glow{from{opacity:.35;transform:scale(.95)}to{opacity:.8;transform:scale(1.08)}}',
           '.wfo-hw-bat{position:absolute;inset-block-start:46%;inset-inline-start:50%;inline-size:70px;block-size:70px;opacity:0;'
           'background:var(--wfo-hw-bat) center/contain no-repeat;filter:drop-shadow(0 0 5px rgba(245,231,168,.85));animation:wfo-hw-fly 2.2s ease-in var(--d) both}',
           '.wfo-hw{--wfo-hw-bat:%s}' % svg(BAT % '#140B1F'),
           '.wfo-rm{--wfo-rm-crescent:%s}' % svg(CRESCENT % '#F5C451'),
           '@keyframes wfo-hw-fly{0%{opacity:0;transform:translate(-50%,0) scale(.3)}10%{opacity:1}'
           '50%{transform:translate(calc(-50% + var(--bx) * .5),calc(var(--by) * .5)) scale(.9,.6)}'
           '60%{transform:translate(calc(-50% + var(--bx) * .6),calc(var(--by) * .6)) scale(1,1.1)}'
           '100%{opacity:0;transform:translate(calc(-50% + var(--bx)),var(--by)) scale(1.2,.7)}}',
           '.wfo-hw-title{display:flex;gap:.06em;justify-content:center;flex-wrap:wrap;direction:ltr;margin:4px 0 0;font-size:clamp(26px,8.6vw,42px);font-weight:900;'
           'letter-spacing:.03em;color:#FF8A1F;text-shadow:0 0 12px rgba(255,138,31,.75),0 3px 0 #6A2C91}',
           '.wfo-hw-title .w{display:inline-flex;white-space:nowrap}.wfo-hw-title .sp{inline-size:.35em}',
           '.wfo-hw-title span{display:inline-block;opacity:0;animation:wfo-hw-letter .6s cubic-bezier(.3,1.6,.5,1) both}',
           '@keyframes wfo-hw-letter{0%{opacity:0;transform:translateY(-40px) rotate(14deg)}60%{opacity:1;transform:translateY(4px) rotate(-6deg)}100%{opacity:1;transform:none}}',
           '.wfo-hw-name{margin:0;font-size:17px;font-weight:700;color:#F5E7A8;opacity:0;animation:wfo-bday-letter .5s ease-out 3.9s both}',
           '@media (prefers-reduced-motion:reduce){.wfo-hw{animation:wfo-bday-out .3s ease-in 3s both}.wfo-hw *{animation:none!important;opacity:1!important}'
           '.wfo-hw-face{fill:#FFD34D}.wfo-hw-bat{display:none}}',
           # the Ramadan moment: a starry night, the crescent glows, a lantern comes down and lights up, "رمضان كريم" written in gold
           '.wfo-rm{position:fixed;inset:0;z-index:9985;pointer-events:none;display:grid;place-items:center;align-content:center;gap:6px;overflow:hidden;'
           'background:radial-gradient(circle at 50% 25%,#24407E 0%,#14244F 45%,#0A1430 100%);animation:wfo-bday-in .5s ease-out both,wfo-bday-out .7s ease-in 5.6s both}',
           '.wfo-rm-stars i{position:absolute;inline-size:var(--s);block-size:var(--s);inset-block-start:var(--y);inset-inline-start:var(--x);'
           'background:radial-gradient(circle,#FFF6D0 0%,rgba(255,246,208,0) 70%);border-radius:50%;opacity:0;animation:wfo-rm-twinkle 1.6s ease-in-out var(--d) infinite alternate}',
           '@keyframes wfo-rm-twinkle{from{opacity:.15;transform:scale(.6)}to{opacity:1;transform:scale(1.2)}}',
           '.wfo-rm-moon{position:absolute;inset-block-start:7%;inset-inline-start:12%;inline-size:96px;block-size:96px;'
           'background:var(--wfo-rm-crescent) center/contain no-repeat;filter:drop-shadow(0 0 18px rgba(245,196,81,.75));'
           'animation:wfo-rm-rise 1.4s cubic-bezier(.2,.9,.3,1) .2s both}',
           '@keyframes wfo-rm-rise{from{opacity:0;transform:translateY(40px) rotate(-25deg)}to{opacity:1;transform:none}}',
           '.wfo-rm-lantern{inline-size:min(40vw,150px);transform-origin:50% 0;animation:wfo-rm-drop 1.3s cubic-bezier(.3,1.25,.5,1) .4s both,wfo-rm-swing 2.6s ease-in-out 1.7s infinite alternate}',
           '@keyframes wfo-rm-drop{from{transform:translateY(-75vh)}to{transform:none}}',
           '@keyframes wfo-rm-swing{from{transform:rotate(-5deg)}to{transform:rotate(5deg)}}',
           '.wfo-rm-glass{fill:#3a2a10;animation:wfo-rm-light .9s ease-in 1.9s forwards}',
           '@keyframes wfo-rm-light{0%{fill:#3a2a10}45%{fill:#FFD86B}60%{fill:#8a6420}100%{fill:#FFE38A}}',
           '.wfo-rm-halo{opacity:0;animation:wfo-hw-glow 1.2s ease-in-out 2.5s infinite alternate}',
           '.wfo-rm-title{margin:8px 0 0;font-size:clamp(40px,13vw,58px);font-weight:800;line-height:1.2;direction:rtl;'
           'background:linear-gradient(100deg,#C99A2E 0%,#FFE7A3 30%,#F5C451 50%,#FFF6D0 62%,#C99A2E 100%);background-size:220% 100%;'
           '-webkit-background-clip:text;background-clip:text;color:transparent;filter:drop-shadow(0 3px 10px rgba(245,196,81,.45));'
           'clip-path:inset(0 0 0 100%);animation:wfo-rm-write 1.3s ease-out 2.9s forwards,wfo-rm-shine 2s linear 4.2s 1 both}',
           '@keyframes wfo-rm-write{to{clip-path:inset(0 0 0 0)}}',
           '@keyframes wfo-rm-shine{from{background-position:100% 0}to{background-position:0 0}}',
           '.wfo-rm-sub{margin:0;font-size:16px;font-weight:600;color:#FFE7A3;letter-spacing:.04em;opacity:0;animation:wfo-bday-letter .5s ease-out 4.1s both}',
           '@media (prefers-reduced-motion:reduce){.wfo-rm{animation:wfo-bday-out .3s ease-in 3s both}.wfo-rm *{animation:none!important;opacity:1!important}'
           '.wfo-rm-glass{fill:#FFE38A}.wfo-rm-title{clip-path:none}}',
           # phones: half the particles
           '@media (max-width:600px){.wfo-occ i:nth-child(even){display:none}}',
           # reduced motion: still, and nothing falls over the screen
           '@media (prefers-reduced-motion:reduce){.wfo-occ i,.wfo-occ-greet{animation:none!important}.wfo-occ i{transform:translate3d(0,calc(var(--rest) * 1px),0) rotate(18deg)}'
           '.wfo-occ.is-page i{transform:translate3d(0,calc(var(--restp) * 1px),0) rotate(12deg)}'
           '.wfo-occ.is-full{display:none}}',
           ]
    for n, (x, sz, d, t) in enumerate(SPOTS, 1):
        out.append('.wfo-occ i:nth-child(%d){--x:%d%%;--s:%dpx;--d:%.1fs;--t:%.1fs;--rest:%d;--restp:%d;--sz:%dpx}' % (n, x, sz, d, t, 46 + (n * 37) % 70, 120 + (n * 211) % 560, sz))
    out.append('.wfo-login .wfo-occ i,.k-occ .wfo-occ i{--rest:400}')
    out.append('/* the palettes and motifs (Step 2: printed inline by Occasions::cssVars() from the catalog, for the one occasion on) */')
    for k, (_, _, pal, mot, _) in OCCASIONS.items():
        out.append('.wfo-occ-%s{--wfo-occ-ink:%s;--wfo-occ-a:%s;--wfo-occ-b:%s;--wfo-occ-c:%s;--wfo-occ-m1:%s;--wfo-occ-m2:%s;--wfo-occ-m3:%s;'
                   '--wfo-occ-tile-head:%s;--wfo-occ-tile-page:%s}' % (k, pal[0], pal[1], pal[2], pal[3], svg(mot[0]), svg(mot[1]), svg(mot[2]), tile(mot, .5), tile(mot, .28)))
    return '\n'.join(out) + '\n'


def layer(mode='bg'):
    return '<div class="wfo-occ is-%s" aria-hidden="true">%s</div>' % (mode, '<i></i>' * 14)


def greet(key, sub=None):
    g = T(*OCCASIONS[key][1])
    subs = {'birthday': ('يوم سعيد من كل الفريق', 'Have a great day, from all the team'),
            'company_birthday': ('شركة النيل للمقاولات · من ٢٠١٤', 'Nile Contracting · since 2014')}
    sub = sub or T(*subs.get(key, ('من أسرة شركة النيل', 'From all of us at Nile Contracting')))
    return greet_html(g, sub)


def greet_html(title, sub):
    return '<div class="wfo-occ-greet" role="status"><div class="wfo-occ-greet-text"><b>%s</b><small>%s</small></div><span class="wfo-occ-greet-art" aria-hidden="true"></span></div>' % (esc(title), esc(sub))


def decorate(html_, key, mode='bg'):
    """The app page with the occasion: the class on .ews-app; "bg" puts the layer in the top bar, "full" over the screen."""
    html_ = html_.replace('<div class="ews-app wfo-app">', '<div class="ews-app wfo-app wfo-occ-on wfo-occ-%s">' % key, 1)
    if mode == 'full':
        return html_.replace('<nav class="wfo-tabbar">', layer('full') + '<nav class="wfo-tabbar">', 1)
    html_ = html_.replace('<main class="ews-main">', '<main class="ews-main">' + layer('page'), 1)
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



CAKE = ('<svg class="wfo-bday-cake" viewBox="0 0 200 210" aria-hidden="true">'
        '<defs><radialGradient id="bf" cx="50%" cy="70%" r="60%"><stop offset="0" stop-color="#FFF6B0"/><stop offset=".55" stop-color="#FFC83D"/><stop offset="1" stop-color="#FF7A1A"/></radialGradient>'
        '<radialGradient id="bg" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#FFE38A" stop-opacity=".9"/><stop offset="1" stop-color="#FFE38A" stop-opacity="0"/></radialGradient></defs>'
        '<ellipse cx="100" cy="196" rx="92" ry="10" fill="#E9D8CF"/><ellipse cx="100" cy="192" rx="86" ry="9" fill="#fff"/>'
        '<rect x="22" y="124" width="156" height="66" rx="14" fill="#F59BB4"/>'
        '<path d="M22 138c0-8 6-14 14-14h128c8 0 14 6 14 14v4c-8 0-8 12-16 12s-8-10-16-10-8 14-16 14-8-12-16-12-8 10-16 10-8-14-16-14-8 12-16 12-8-10-16-10-8 8-12 8z" fill="#FFF4F7"/>'
        '<rect x="46" y="74" width="108" height="56" rx="12" fill="#8E6CF0"/>'
        '<path d="M46 86c0-7 5-12 12-12h84c7 0 12 5 12 12v2c-6 0-6 10-12 10s-6-8-12-8-6 12-12 12-6-10-12-10-6 8-12 8-6-12-12-12-6 10-12 10-6-8-12-8z" fill="#FFF4F7"/>'
        + ''.join('<rect x="%d" y="%d" width="7" height="3" rx="1.5" fill="%s" transform="rotate(%d %d %d)"/>' % (x, y, c, r, x, y) for x, y, c, r in
                  [(40, 166, '#FFC83D', 20), (70, 176, '#4D96FF', -30), (102, 168, '#2BA36B', 40), (132, 178, '#FFC83D', -10), (156, 164, '#4D96FF', 25),
                   (64, 108, '#FFC83D', -20), (96, 116, '#F59BB4', 30), (128, 106, '#2BA36B', -35)])
        + '<rect x="94" y="34" width="12" height="42" rx="4" fill="#fff"/><path d="M94 42l12-6M94 54l12-6M94 66l12-6" stroke="#E8436A" stroke-width="4"/>'
        '<rect x="99" y="28" width="2" height="8" fill="#5a4636"/>'
        '<circle class="wfo-bday-glow" cx="100" cy="16" r="22" fill="url(#bg)"/>'
        '<path class="wfo-bday-flame" d="M100 0c6 9 10 14 10 20a10 10 0 0 1-20 0c0-6 4-11 10-20z" fill="url(#bf)"/>'
        '<g class="wfo-bday-smoke" fill="#B9B3AE"><circle cx="100" cy="24" r="5"/><circle cx="96" cy="22" r="4"/><circle cx="104" cy="23" r="3.5"/></g>'
        '</svg>')


def bday_scene(name):
    word = 'HAPPY BIRTHDAY'
    letters, i = [], 0
    for w in word.split(' '):
        letters.append('<b class="w">%s</b>' % ''.join('<span style="animation-delay:%.2fs">%s</span>' % (2.75 + (i + k) * .07, ch) for k, ch in enumerate(w)))
        i += len(w) + 1
    letters = '<span class="sp"></span>'.join(letters)
    colors = ['#E8436A', '#FFC83D', '#4D96FF', '#2BA36B', '#8E6CF0']
    burst = ''.join('<i style="background:%s;--bx:%dpx;--by:%dpx;--br:%ddeg"></i>' % (colors[i % 5], int(170 * __import__('math').cos(i * .52)), int(-60 - 150 * abs(__import__('math').sin(i * .52))) if i % 2 else int(120 * __import__('math').sin(i)), (i * 97) % 720)
                    for i in range(24))
    return ('<div class="wfo-bday" role="status" aria-label="%s">%s<p class="wfo-bday-title">%s</p><p class="wfo-bday-name">%s</p><div class="wfo-bday-burst">%s</div></div>') % (
        esc(T('كل سنة وانت طيب', 'Happy birthday')), CAKE, letters, esc(name), burst)


def birthday_cake():
    page = decorate(app_page(T('الرئيسية', 'Dashboard'), home_body('birthday'), active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'birthday')
    return page.replace('<nav class="wfo-tabbar">', bday_scene(T('كل سنة وانت طيب يا عمر!', 'Many happy returns, Omar!')) + '<nav class="wfo-tabbar">', 1)



PUMPKIN_BIG = ('<svg class="wfo-hw-pumpkin" viewBox="0 0 220 200" aria-hidden="true">'
               '<defs><radialGradient id="hwg" cx="50%" cy="55%" r="50%"><stop offset="0" stop-color="#FFD34D" stop-opacity=".9"/><stop offset="1" stop-color="#FF8A1F" stop-opacity="0"/></radialGradient></defs>'
               '<ellipse cx="110" cy="190" rx="80" ry="8" fill="#000" opacity=".35"/>'
               '<ellipse class="wfo-hw-glow" cx="110" cy="118" rx="110" ry="90" fill="url(#hwg)"/>'
               '<path d="M104 38c-2-14 4-26 16-30l4 6c-8 4-10 12-8 24z" fill="#3C8D5A"/>'
               '<ellipse cx="62" cy="118" rx="46" ry="66" fill="#E8661A"/><ellipse cx="158" cy="118" rx="46" ry="66" fill="#E8661A"/>'
               '<ellipse cx="88" cy="116" rx="42" ry="72" fill="#F2761B"/><ellipse cx="132" cy="116" rx="42" ry="72" fill="#F2761B"/>'
               '<ellipse cx="110" cy="116" rx="34" ry="74" fill="#FF8A2A"/>'
               '<path class="wfo-hw-face" d="M62 92l22-14 10 24zM158 92l-22-14-10 24zM100 116h20l-10 16z'
               'M56 136c18 22 90 22 108 0l-6 22-12-8-10 12-10-10-10 12-10-12-10 10-12-12-12 8z"/>'
               '</svg>')
BAT_URL = svg(BAT % '#140B1F')


def halloween_scene():
    word = 'HAPPY HALLOWEEN'
    letters, i = [], 0
    for w in word.split(' '):
        letters.append('<b class="w">%s</b>' % ''.join('<span style="animation-delay:%.2fs">%s</span>' % (2.9 + (i + k) * .08, ch) for k, ch in enumerate(w)))
        i += len(w) + 1
    bats = ''.join('<i class="wfo-hw-bat" style="--bx:%dpx;--by:%dpx;--d:%.2fs"></i>' % (bx, by, d) for bx, by, d in
                   [(-190, -330, 1.7), (170, -300, 1.85), (-120, -420, 2.0), (210, -180, 2.1), (-230, -150, 2.25), (60, -440, 2.35), (-40, -380, 2.5)])
    return ('<div class="wfo-hw" role="status" aria-label="%s"%s><span class="wfo-hw-moon" aria-hidden="true"></span>%s%s<p class="wfo-hw-title">%s</p><p class="wfo-hw-name">%s</p></div>') % (
        esc(T('هالوين سعيد', 'Happy Halloween')), '', PUMPKIN_BIG, bats, '<span class="sp"></span>'.join(letters), esc(T('هالوين سعيد من أسرة شركة النيل', 'Happy Halloween from all of us at Nile')))


def halloween_moment():
    page = decorate(app_page(T('الرئيسية', 'Dashboard'), home_body('halloween'), active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'halloween')
    return page.replace('<nav class="wfo-tabbar">', halloween_scene() + '<nav class="wfo-tabbar">', 1)



LANTERN_BIG = ('<svg class="wfo-rm-lantern" viewBox="0 0 120 300" aria-hidden="true">'
               '<defs><radialGradient id="rmh" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#FFE38A" stop-opacity=".95"/><stop offset="1" stop-color="#FFE38A" stop-opacity="0"/></radialGradient>'
               '<linearGradient id="rmg" x1="0" x2="1"><stop offset="0" stop-color="#A87A1E"/><stop offset=".5" stop-color="#F5C451"/><stop offset="1" stop-color="#A87A1E"/></linearGradient></defs>'
               '<path d="M60 0v92" stroke="#C99A2E" stroke-width="2"/>'
               '<circle class="wfo-rm-halo" cx="60" cy="190" r="75" fill="url(#rmh)"/>'
               '<circle cx="60" cy="98" r="8" fill="none" stroke="url(#rmg)" stroke-width="4"/>'
               '<path d="M40 128q20-26 40 0z" fill="url(#rmg)"/><rect x="34" y="126" width="52" height="8" rx="3" fill="url(#rmg)"/>'
               '<path d="M36 134h48l14 30v60l-14 26H36l-14-26v-60z" fill="url(#rmg)"/>'
               '<path class="wfo-rm-glass" d="M40 142h12v96H40l-10-18v-60zM56 142h8v96h-8zM68 142h12l10 18v60l-10 18H68z"/>'
               '<path d="M30 192h60M33 166h54M33 216h54" stroke="#A87A1E" stroke-width="2.5"/>'
               '<path d="M34 250h52l-8 14H42z" fill="url(#rmg)"/><path d="M50 264h20l-10 18z" fill="url(#rmg)"/><circle cx="60" cy="288" r="5" fill="#F5C451"/>'
               '</svg>')


def ramadan_scene():
    import random
    rnd = random.Random(7)
    stars = ''.join('<i style="--x:%d%%;--y:%d%%;--s:%dpx;--d:%.2fs"></i>' % (rnd.randint(2, 97), rnd.randint(2, 70), rnd.choice([4, 6, 8, 10]), rnd.random() * 1.5) for _ in range(34))
    return ('<div class="wfo-rm" role="status" aria-label="%s"><div class="wfo-rm-stars" aria-hidden="true">%s</div><span class="wfo-rm-moon" aria-hidden="true"></span>%s'
            '<p class="wfo-rm-title">رمضان كريم</p><p class="wfo-rm-sub">%s</p></div>') % (
                esc(T('رمضان كريم', 'Ramadan Kareem')), stars, LANTERN_BIG, esc(T('كل سنة وانتم طيبين · من أسرة شركة النيل', 'Ramadan Kareem from all of us at Nile')))


def ramadan_moment():
    page = decorate(app_page(T('الرئيسية', 'Dashboard'), home_body('ramadan'), active='dashboard', css_files=['app-home.css'], feature_css=CSS, who=WHO), 'ramadan')
    return page.replace('<nav class="wfo-tabbar">', ramadan_scene() + '<nav class="wfo-tabbar">', 1)


def birthday_everyone():
    """A colleague on the day of someone the administrator picked: everyone sees it."""
    def make():
        body = home_body(None, [('cake', T('عيد ميلاد سعيد يا م. خالد!', 'Happy birthday, Eng. Khaled!'), T('المدير العام', 'General Manager'))])
        body = greet_html(T('كل سنة وانت طيب يا م. خالد!', 'Happy birthday, Eng. Khaled!'), T('النهارده عيد ميلاد المدير العام', "It's our General Manager's birthday today")) + body
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
                      '<small class="occ-help">%s</small>'
                      '<label class="occ-check" style="margin-top:12px"><input type="checkbox" checked> %s<small class="occ-help">%s</small></label>'
                      '<label class="occ-check"><input type="checkbox"> %s<small class="occ-help">%s</small></label></div>') % (
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
                          esc(T('في يوم ميلادهم الزينة والتهنئة باسمهم تظهر لكل الموظفين (مش في الكشك ولا صفحة الدخول).', 'On their day the decorations and a greeting with their name show to every employee (not on kiosks or the login page).')),
                          esc(T('مشهد التورتة لصاحب عيد الميلاد', 'The cake moment for the birthday person')),
                          esc(T('تورتة وشمعة بتطفي و"HAPPY BIRTHDAY"، مرة واحدة في اليوم أول ما يفتح التطبيق.', 'A cake, its candle blown out and "HAPPY BIRTHDAY", once a day when they open the app.')),
                          esc(T('مشهد التورتة لكل الموظفين في عيد ميلاد الأشخاص المختارين', 'The cake moment for everyone on the picked people\'s birthday')),
                          esc(T('مرة واحدة في اليوم لكل موظف.', 'Once a day for each employee.')))
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
    write(F, 'birthday-cake', birthday_cake)
    write(F, 'halloween-moment', halloween_moment)
    write(F, 'ramadan-moment', ramadan_moment)
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
         [('dash-ramadan-full', 'Ramadan, full screen · رمضان، الشاشة كلها', 'phone'), ('halloween-moment', 'Halloween moment, once a day (reload to replay) · لحظة الهالوين', 'phone'), ('ramadan-moment', 'Ramadan moment, once a day · لحظة رمضان', 'phone')]),
        ('4. Birthdays · أعياد الميلاد', 'Omar on his birthday (only he sees it); Mona on the birthday of the General Manager, whom the administrator picked (everyone sees it).',
         [('birthday-cake', 'The cake, once a day (reload to replay) · التورتة، مرة في اليوم', 'phone'), ('birthday-me', 'My birthday · عيد ميلادي', 'phone'), ('birthday-everyone', 'A picked person · شخص مختار', 'phone')]),
        ('5. Sign In / Out, the menu, the login page · الحضور والقائمة وصفحة الدخول', '',
         [('time', 'Sign In / Out · الحضور', 'phone'), ('menu', 'Hide decorations · إخفاء الزينة', 'phone'), ('login', 'Login page · صفحة الدخول', 'phone')]),
        ('6. Kiosk · الكشك', 'The company occasion only, slow and continuous, behind the QR card; never names.', [('kiosk', 'Kiosk · الكشك', 'desk')]),
    ])
