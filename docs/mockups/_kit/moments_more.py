"""More "moments" for the occasions (owner 2026-11-09: "اعمل باقي المناسبات"): Valentine's Day, Eid al-Fitr, Eid al-Adha, New Year,
National day (Egypt). Each is a full-screen scene of about 6 seconds, once a day for each employee while that occasion is on; it never
takes a tap and stays under the tab bar; reduced motion shows the final picture, still. The CSS is appended to occasions.css."""
import math, random
from build import T, esc

CSS = r'''
/* ---- the moments for the other occasions (one generic frame, an art per occasion) */
.wfo-mo{position:fixed;inset:0;z-index:9985;pointer-events:none;display:grid;place-items:center;align-content:center;gap:6px;overflow:hidden;
background:var(--mo-bg);animation:wfo-bday-in .5s ease-out both,wfo-bday-out .7s ease-in 5.6s both}
.wfo-mo-art{position:relative;animation:wfo-bday-pop .8s cubic-bezier(.2,1.5,.4,1) .2s both}
.wfo-mo-ar{margin:8px 0 0;font-size:clamp(30px,10vw,48px);white-space:nowrap;font-weight:800;line-height:1.25;direction:rtl;text-align:center;padding-inline:12px;
background:var(--mo-gold);background-size:220% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;
filter:drop-shadow(0 3px 10px var(--mo-glow));clip-path:inset(0 0 0 100%);animation:wfo-rm-write 1.3s ease-out 2.9s forwards,wfo-rm-shine 2s linear 4.2s 1 both}
.wfo-mo-en{display:flex;gap:.06em;justify-content:center;flex-wrap:wrap;direction:ltr;margin:8px 0 0;font-size:clamp(26px,8.6vw,42px);font-weight:900;
letter-spacing:.03em;color:var(--mo-ink);text-shadow:var(--mo-shadow)}
.wfo-mo-en .w{display:inline-flex;white-space:nowrap}
.wfo-mo-valentine .wfo-mo-en{font-size:clamp(20px,6.6vw,34px)}.wfo-mo-en .sp{inline-size:.35em}
.wfo-mo-en span{display:inline-block;opacity:0;animation:wfo-bday-letter .45s cubic-bezier(.2,1.6,.4,1) both}
.wfo-mo-sub{margin:0;font-size:16px;font-weight:600;color:var(--mo-sub);opacity:0;animation:wfo-bday-letter .5s ease-out 4.1s both;text-align:center;padding-inline:16px}
.wfo-mo .wfo-bday-burst i{animation-delay:2.8s}
/* rising things (hearts) */
.wfo-mo-rise i{position:absolute;inset-block-end:-60px;inset-inline-start:var(--x);inline-size:var(--s);block-size:var(--s);background:var(--m) center/contain no-repeat;
opacity:0;animation:wfo-mo-rise var(--t) ease-in var(--d) both}
@keyframes wfo-mo-rise{0%{opacity:0;transform:translateY(0) rotate(0)}15%{opacity:.95}100%{opacity:0;transform:translateY(-110vh) rotate(var(--r))}}
/* Valentine: the heart fills, then beats */
.wfo-mo-heart{inline-size:min(52vw,200px);animation:wfo-mo-beat .9s ease-in-out 2.2s 2}
@keyframes wfo-mo-beat{0%,100%{transform:scale(1)}30%{transform:scale(1.14)}50%{transform:scale(.97)}70%{transform:scale(1.08)}}
.wfo-mo-fill{animation:wfo-mo-fill 1.6s cubic-bezier(.4,0,.2,1) .7s both}
@keyframes wfo-mo-fill{from{transform:translateY(100%)}to{transform:translateY(0)}}
/* garlands that come down and swing (Eid) */
.wfo-mo-garland{position:absolute;inset:0 0 auto;block-size:46%;display:flex;justify-content:space-around;padding-inline:4%}
.wfo-mo-garland b{position:relative;display:block;inline-size:2px;block-size:var(--l);background:var(--mo-thread);transform-origin:50% 0;
animation:wfo-rm-drop 1.1s cubic-bezier(.3,1.25,.5,1) var(--d) both,wfo-rm-swing 2.2s ease-in-out calc(var(--d) + 1.1s) infinite alternate}
.wfo-mo-garland b::after{content:"";position:absolute;inset-block-start:100%;inset-inline-start:50%;inline-size:var(--s);block-size:var(--s);margin-inline-start:calc(var(--s) / -2);
background:var(--m) center/contain no-repeat;filter:drop-shadow(0 0 8px var(--mo-glow))}
.wfo-mo-crescent{inline-size:min(44vw,170px);filter:drop-shadow(0 0 22px var(--mo-glow))}
.wfo-mo-crescent .st{transform-box:fill-box;transform-origin:50% 50%;animation:wfo-mo-spin 1.4s ease-out 1.2s both}
@keyframes wfo-mo-spin{from{transform:scale(0) rotate(-180deg)}to{transform:none}}
/* New Year: 3, 2, 1, then the year; soft fireworks (no strobe) */
.wfo-mo-count{position:relative;block-size:150px;inline-size:220px;display:grid;place-items:center}
.wfo-mo-count span{position:absolute;font-size:110px;font-weight:900;color:#FFE7A3;text-shadow:0 0 30px rgba(245,196,81,.7);opacity:0;animation:wfo-mo-tick .62s ease-out var(--d) both}
@keyframes wfo-mo-tick{0%{opacity:0;transform:scale(2.2)}25%{opacity:1;transform:scale(1)}80%{opacity:1}100%{opacity:0;transform:scale(.6)}}
.wfo-mo-count .yr{font-size:84px;animation:wfo-mo-year .8s cubic-bezier(.2,1.5,.4,1) 2.15s both;background:linear-gradient(100deg,#C99A2E,#FFF6D0 45%,#F5C451 60%,#C99A2E);
-webkit-background-clip:text;background-clip:text;color:transparent}
@keyframes wfo-mo-year{from{opacity:0;transform:scale(.3)}to{opacity:1;transform:none}}
.wfo-mo-fw{position:absolute;inset-block-start:var(--y);inset-inline-start:var(--x)}
.wfo-mo-fw i{position:absolute;inline-size:7px;block-size:7px;border-radius:50%;background:var(--c);box-shadow:0 0 10px var(--c);opacity:0;
animation:wfo-mo-fw 1.5s cubic-bezier(.1,.8,.3,1) var(--d) both}
@keyframes wfo-mo-fw{0%{opacity:0;transform:rotate(var(--a)) translateX(0)}10%{opacity:1}100%{opacity:0;transform:rotate(var(--a)) translateX(var(--r)) translateY(26px)}}
/* National day: three bands that wave across */
.wfo-mo-bands{position:absolute;inset-inline:-20%;inset-block-start:16%;block-size:150px;opacity:0;animation:wfo-bday-in .8s ease-out .3s forwards}
.wfo-mo-bands{overflow:hidden;inset-inline:0}.wfo-mo-bands svg{display:block;inline-size:200%;block-size:100%;animation:wfo-mo-wave 3s linear infinite}
@keyframes wfo-mo-wave{from{transform:translateX(0)}to{transform:translateX(-50%)}}
[dir=rtl] .wfo-mo-bands svg{animation-name:wfo-mo-wave-rtl}@keyframes wfo-mo-wave-rtl{from{transform:translateX(0)}to{transform:translateX(50%)}}
@media (prefers-reduced-motion:reduce){.wfo-mo{animation:wfo-bday-out .3s ease-in 3s both}.wfo-mo *{animation:none!important;opacity:1!important}
.wfo-mo-ar{clip-path:none}.wfo-mo-rise,.wfo-mo-fw,.wfo-mo .wfo-bday-burst,.wfo-mo-count span:not(.yr){display:none}.wfo-mo-fill{transform:none}}
'''


def letters(word, start=2.75, step=.07):
    out, i = [], 0
    for w in word.split(' '):
        out.append('<b class="w">%s</b>' % ''.join('<span style="animation-delay:%.2fs">%s</span>' % (start + (i + k) * step, ch) for k, ch in enumerate(w)))
        i += len(w) + 1
    return '<span class="sp"></span>'.join(out)


def burst(colors, n=24):
    out = ''
    for i in range(n):
        a = i * 2 * math.pi / n
        out += '<i style="background:%s;--bx:%dpx;--by:%dpx;--br:%ddeg"></i>' % (colors[i % len(colors)], int(170 * math.cos(a)), int(-40 - 160 * abs(math.sin(a)) if i % 2 else 140 * math.sin(a)), (i * 97) % 720)
    return '<div class="wfo-bday-burst">%s</div>' % out


def frame(key, style, inner, ar_title, en_title, sub, en_first=False):
    head = ('<p class="wfo-mo-en">%s</p>' % letters(en_title)) if en_first else ('<p class="wfo-mo-ar">%s</p>' % esc(ar_title))
    return '<div class="wfo-mo wfo-mo-%s" role="status" aria-label="%s" style="%s">%s%s<p class="wfo-mo-sub">%s</p></div>' % (
        key, esc(T(ar_title, en_title)), style, inner, head, esc(sub))


def valentine(svg, heart_shape):
    rnd = random.Random(3)
    rise = ''.join('<i style="--x:%d%%;--s:%dpx;--d:%.2fs;--t:%.1fs;--r:%ddeg"></i>' % (rnd.randint(2, 94), rnd.choice([18, 24, 30, 38]), rnd.random() * 3.2, 3.2 + rnd.random() * 1.8, rnd.randint(-40, 40)) for _ in range(22))
    heart = ('<svg class="wfo-mo-heart" viewBox="0 0 24 24" aria-hidden="true"><defs><clipPath id="vh"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 2.9 4.5 6.6 4.5c2.2 0 3.6 1.2 5.4 3.2 1.8-2 3.2-3.2 5.4-3.2 3.7 0 5.7 3.9 4.2 7.3C19.5 16.4 12 21 12 21z"/></clipPath>'
             '<linearGradient id="vg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FF6F91"/><stop offset="1" stop-color="#D81E5B"/></linearGradient></defs>'
             '<path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 2.9 4.5 6.6 4.5c2.2 0 3.6 1.2 5.4 3.2 1.8-2 3.2-3.2 5.4-3.2 3.7 0 5.7 3.9 4.2 7.3C19.5 16.4 12 21 12 21z" fill="rgba(255,255,255,.18)" stroke="#fff" stroke-width=".7"/>'
             '<g clip-path="url(#vh)"><rect class="wfo-mo-fill" x="0" y="0" width="24" height="24" fill="url(#vg)"/></g>'
             '<path d="M7 8.5c-1 .4-1.6 1.4-1.6 2.4" stroke="#fff" stroke-opacity=".7" stroke-width=".8" fill="none" stroke-linecap="round"/></svg>')
    style = ("--mo-bg:radial-gradient(circle at 50% 40%,#FFD6E2 0%,#FF8FAB 55%,#E8436A 100%);--mo-ink:#fff;--mo-shadow:0 3px 0 #B0124A,0 8px 22px rgba(122,16,48,.4);"
             "--mo-sub:#fff;--mo-glow:rgba(255,255,255,.6)")
    inner = '<div class="wfo-mo-rise" aria-hidden="true" style=\'--m:%s\'>%s</div><div class="wfo-mo-art">%s</div>' % (svg(heart_shape % '#fff'), rise, heart)
    return frame('valentine', style, inner, 'عيد حب سعيد', "HAPPY VALENTINE'S DAY", T('عيد حب سعيد من أسرة شركة النيل', "Happy Valentine's Day from all of us at Nile"), en_first=True)


def eid(svg, key, crescent, star, extra, palette):
    bg, gold, glow, thread, title, sub_ar, sub_en, burst_colors = palette
    items = [crescent, star, extra, star, crescent, extra, star]
    garland = ''.join('<b style=\'--l:%dpx;--s:%dpx;--d:%.2fs;--m:%s\'></b>' % (l, sz, d, svg(items[i]))
                      for i, (l, sz, d) in enumerate([(60, 30, .3), (110, 24, .45), (80, 34, .6), (130, 22, .75), (70, 32, .9), (115, 26, 1.05), (85, 30, 1.2)]))
    big = ('<svg class="wfo-mo-crescent" viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="eg%s" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#FFF6D0"/><stop offset="1" stop-color="#F5C451"/></linearGradient></defs>'
           '<path fill="url(#eg%s)" d="M15.5 3.2A9 9 0 1 0 20.8 16 7.2 7.2 0 0 1 15.5 3.2z"/>'
           '<path class="st" fill="#FFF6D0" d="M17.5 6.5l.9 2.1 2.3.2-1.7 1.5.5 2.2-2-1.2-2 1.2.5-2.2-1.7-1.5 2.3-.2z"/></svg>') % (key, key)
    style = "--mo-bg:%s;--mo-gold:%s;--mo-glow:%s;--mo-thread:%s;--mo-sub:#FFF6D0" % (bg, gold, glow, thread)
    inner = '<div class="wfo-mo-garland" aria-hidden="true">%s</div><div class="wfo-mo-art">%s</div>%s' % (garland, big, burst(burst_colors))
    return frame(key, style, inner, title, title, T(sub_ar, sub_en))


def new_year():
    year = '2027'
    count = ''.join('<span style="--d:%.2fs">%s</span>' % (.4 + i * .6, n) for i, n in enumerate(['3', '2', '1'])) + '<span class="yr">%s</span>' % year
    fws = ''
    for (x, y, d, c) in [(22, 18, 2.2, '#F5C451'), (76, 14, 2.6, '#D6338A'), (50, 8, 3.0, '#C9D3E0'), (16, 46, 3.4, '#D6338A'), (82, 44, 3.8, '#F5C451')]:
        fws += '<div class="wfo-mo-fw" style="--x:%d%%;--y:%d%%" aria-hidden="true">%s</div>' % (x, y, ''.join(
            '<i style="--a:%ddeg;--r:%dpx;--d:%.2fs;--c:%s"></i>' % (k * 30, 60 + (k % 3) * 12, d, c) for k in range(12)))
    style = ("--mo-bg:radial-gradient(circle at 50% 30%,#3B1F5C 0%,#1E1035 55%,#0C0618 100%);--mo-ink:#FFE7A3;"
             "--mo-shadow:0 0 14px rgba(245,196,81,.6),0 3px 0 #6A2C91;--mo-sub:#E9DDF7")
    inner = fws + '<div class="wfo-mo-count" aria-hidden="true">%s</div>' % count + burst(['#F5C451', '#D6338A', '#C9D3E0', '#fff'])
    return frame('new_year', style, inner, 'سنة سعيدة', 'HAPPY NEW YEAR', T('سنة سعيدة من أسرة شركة النيل', 'A happy new year from all of us at Nile'), en_first=True)


def national():
    bands = ('<div class="wfo-mo-bands" aria-hidden="true"><svg viewBox="0 0 1400 150" preserveAspectRatio="none">'
             + ''.join('<path d="M0 %d %s V%d H0z" fill="%s"/>' % (
                 y, ' '.join('Q%d %d %d %d' % (x + 50, y - 18 if (x // 100) % 2 == 0 else y + 18, x + 100, y) for x in range(0, 1400, 100)), y + 50, c)
                 for y, c in [(0, '#CE1126'), (50, '#FFFFFF'), (100, '#1A1A1A')])
             + '</svg></div>')
    fws = ''
    for (x, y, d, c) in [(20, 62, 2.4, '#CE1126'), (80, 60, 2.8, '#FFFFFF'), (50, 72, 3.2, '#C9A227')]:
        fws += '<div class="wfo-mo-fw" style="--x:%d%%;--y:%d%%" aria-hidden="true">%s</div>' % (x, y, ''.join(
            '<i style="--a:%ddeg;--r:%dpx;--d:%.2fs;--c:%s"></i>' % (k * 30, 54 + (k % 3) * 12, d, c) for k in range(12)))
    style = ("--mo-bg:radial-gradient(circle at 50% 35%,#3a3a3a 0%,#1d1d1d 60%,#0d0d0d 100%);--mo-gold:linear-gradient(100deg,#CE1126 0%,#FF5A6E 35%,#FFFFFF 50%,#FF5A6E 65%,#CE1126 100%);"
             "--mo-glow:rgba(206,17,38,.55);--mo-sub:#F2F2F2")
    return frame('national', style, bands + fws + '<div style="block-size:150px"></div>', 'كل سنة ومصر بخير', 'Happy National Day',
                 T('من أسرة شركة النيل', 'From all of us at Nile Contracting'))
