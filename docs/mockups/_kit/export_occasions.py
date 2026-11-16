"""Writes the plugin's occasion art from the approved mockups (docs/tasks/occasions.md):
- workforce-one/assets/css/occasions.css (+ -rtl, identical: logical properties): the shared rules and the moments;
- workforce-one/src/Settings/OccasionArt.php: each occasion's palette and motifs (CSS variables, printed inline for the one occasion on)
  and each moment's art (HTML without its words; the plugin adds the title and the line, escaped).
Run after changing screens_occasions.py or moments_more.py:  python3 docs/mockups/_kit/export_occasions.py
"""
import os, re, sys
KIT = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, KIT)
import build
build.Lang.code = 'en'
import screens_occasions as so, moments_more as mm

REPO = os.path.dirname(os.path.dirname(os.path.dirname(KIT)))
PLUGIN = os.path.join(REPO, 'workforce-one')

css = so.stylesheet()
marker = '/* the palettes and motifs'
shared, palettes = css.split(marker, 1)
EXTRA = ('/* the words of the cake, Halloween and Ramadan moments use the generic title classes */\n'
         '.wfo-bday{--mo-ink:#E8436A;--mo-shadow:0 3px 0 #FFC83D,0 6px 18px rgba(232,67,106,.35);--mo-sub:#7A2E00;'
         '--mo-gold:linear-gradient(100deg,#B0124A,#E8436A 40%,#FF8FAB 55%,#E8436A 70%,#B0124A);--mo-glow:rgba(232,67,106,.35)}\n'
         '.wfo-hw{--mo-ink:#FF8A1F;--mo-shadow:0 0 12px rgba(255,138,31,.75),0 3px 0 #6A2C91;--mo-sub:#F5E7A8;'
         '--mo-gold:linear-gradient(100deg,#C2410C,#FF8A1F 40%,#FFD34D 55%,#FF8A1F 70%,#C2410C);--mo-glow:rgba(255,138,31,.55)}\n'
         '.wfo-rm{--mo-ink:#FFE7A3;--mo-shadow:0 0 14px rgba(245,196,81,.6),0 3px 0 #14244F;--mo-sub:#FFE7A3;'
         '--mo-gold:linear-gradient(100deg,#C99A2E 0%,#FFE7A3 30%,#F5C451 50%,#FFF6D0 62%,#C99A2E 100%);--mo-glow:rgba(245,196,81,.45)}\n'
         '.wfo-occ-greet-text b,.wfo-occ-greet-text small{overflow-wrap:anywhere}\n'
         '.wfo-occ-greet-hide{position:absolute;inset-block-start:8px;inset-inline-end:12px;z-index:1;font-size:12px;font-weight:600;color:rgba(255,255,255,.8);text-decoration:underline}\n'
         '.wfo-occ-greet{padding-block-start:26px}\n')
header = '/* Occasions (docs/tasks/occasions.md): written by docs/mockups/_kit/export_occasions.py from the approved mockups; do not edit by hand. */\n'
out_css = header + shared.split('\n', 4)[-1] if False else header + shared + mm.CSS + EXTRA
for n in ('occasions.css', 'occasions-rtl.css'):
    open(os.path.join(PLUGIN, 'assets', 'css', n), 'w').write(out_css)

vars_ = {}
for m in re.finditer(r'^\.wfo-occ-([a-z_]+)\{(.*)\}$', palettes, re.M):
    vars_[m.group(1)] = m.group(2)
extra_vars = {'cake': '', 'halloween_bat': ''}

TITLE_RE = re.compile(r'<p class="(?:wfo-bday-title|wfo-bday-name|wfo-hw-title|wfo-hw-name|wfo-rm-title|wfo-rm-sub|wfo-mo-ar|wfo-mo-en|wfo-mo-sub)">.*?</p>', re.S)


def split(scene):
    m = re.match(r'<div class="([^"]+)" role="status" aria-label="[^"]*"(?: style="([^"]*)")?>(.*)</div>$', scene, re.S)
    assert m, scene[:200]
    cls, style, inner = m.group(1), m.group(2) or '', m.group(3)
    return cls, style, TITLE_RE.sub('', inner)


gold = 'linear-gradient(100deg,#C99A2E 0%,#FFE7A3 30%,#F5C451 50%,#FFF6D0 62%,#C99A2E 100%)'
scenes = {
    'birthday': so.bday_scene('x'),
    'halloween': so.halloween_scene(),
    'ramadan': so.ramadan_scene(),
    'valentine': mm.valentine(so.svg, so.HEART),
    'eid_fitr': mm.eid(so.svg, 'eid_fitr', so.CRESCENT % '#F5C451', so.STAR % '#FFF6D0', so.STAR % '#2BA36B',
                       ('radial-gradient(circle at 50% 35%,#1E8A5A 0%,#0F5132 55%,#07301D 100%)', gold, 'rgba(245,196,81,.55)', '#F5C451', 'x', 'x', 'x', ['#F5C451', '#2BA36B', '#FFF6D0', '#BFF0D4'])),
    'eid_adha': mm.eid(so.svg, 'eid_adha', so.CRESCENT % '#F5C451', so.STAR % '#FFE7A3', so.LANTERN % ('#C99A2E', '#F5C451', '#F5C451', '#C99A2E'),
                       ('radial-gradient(circle at 50% 35%,#8A5420 0%,#5A3410 55%,#2E1A06 100%)', gold, 'rgba(245,196,81,.55)', '#F5C451', 'x', 'x', 'x', ['#F5C451', '#C8792A', '#FFF6D0', '#3C8D5A'])),
    'new_year': mm.new_year(),
    'national': mm.national(),
}


def php(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


lines = ['<?php', 'namespace WorkforceOne\\Settings;', '', "if (!defined('ABSPATH')) exit;", '', '/**',
         ' * The occasions\' art (docs/tasks/occasions.md), written by docs/mockups/_kit/export_occasions.py from the approved mockups; do not',
         ' * edit by hand. VARS: each occasion\'s palette and motifs as CSS custom properties (inline SVG, colours only), printed for the one',
         ' * occasion on. MOMENTS: each moment\'s container class and style and its art without words ({{year}} = the new year).',
         ' */', 'final class OccasionArt', '{', '    public const VARS = [']
for k, v in vars_.items():
    lines.append('        %s => %s,' % (php(k), php(v)))
lines.append('    ];')
lines.append('')
lines.append('    /** @var array<string,array{0:string,1:string,2:string}> class, style, art */')
lines.append('    public const MOMENTS = [')
for k, sc in scenes.items():
    cls, style, inner = split(sc)
    if k == 'new_year':
        inner = inner.replace('>2027<', '>{{year}}<')
    lines.append('        %s => [%s, %s, %s],' % (php(k), php(cls), php(style), php(inner)))
lines.append('    ];')
lines.append('}')
open(os.path.join(PLUGIN, 'src', 'Settings', 'OccasionArt.php'), 'w').write('\n'.join(lines) + '\n')
print('css', len(out_css), 'php', os.path.getsize(os.path.join(PLUGIN, 'src', 'Settings', 'OccasionArt.php')), 'keys', list(vars_), list(scenes))
