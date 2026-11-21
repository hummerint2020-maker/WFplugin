"""Leave in My requests (owner 2026-11-21, docs/tasks/leave-in-requests.md): "cancelling an approved leave... the Leave
page has no use any more". My requests gains: a balances strip (every leave type), "Request cancellation" on an approved
leave that has not started, the cancellation's states, and a Leave filter with the whole history. The old Leave page stays
behind a switch (shown until the owner turns it off).

The screens start from snapshots of the real My requests page (_kit/snap/requests.{ar,en}.html, emp1, 3.31.157);
leave-in-requests.css is what Step 2 would add to app-approvals.css."""
import os, re, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build import T, Lang, esc, icon, write, gallery, Build

F = 'leave-in-requests'
KIT = os.path.dirname(os.path.abspath(__file__))


def shell(body):
    h = open(os.path.join(KIT, 'snap', 'requests.%s.html' % Lang.code)).read().replace('{ASSETS}', Build.assets)
    a = h.index('<div class="wfo-apv is-mine">')
    b = h.index('<dialog class="wfo-sheet wfo-new"')
    css = '<link rel="stylesheet" href="leave-in-requests%s.css">' % ('-rtl' if Lang.rtl() else '')
    return h[:a].replace('</head>', css + '</head>', 1) + body + h[b:]


BAL = [(('سنوية', 'Annual Leave'), 14, 21), (('عارضة', 'Casual Leave'), 4, 7), (('مرضية', 'Sick Leave'), 10, 15), (('بدون مرتب', 'Unpaid Leave'), None, None)]


def balances():
    cells = ''
    for i, (n, left, of) in enumerate(BAL):
        val = ('<b>%d</b><small>%s</small>' % (left, esc(T('من %d يوم' % of, 'of %d days' % of)))) if left is not None else '<b>—</b><small>%s</small>' % esc(T('بدون رصيد', 'No balance'))
        cells += '<div class="wfo-lr-bal tone-%d"><span>%s</span>%s</div>' % (i % 4, esc(T(*n)), val)
    return ('<section class="wfo-lr-bals" aria-label="%s"><div class="wfo-lr-bals-h">%s<b>%s</b><small>%s</small></div><div class="wfo-lr-bals-row">%s</div></section>') % (
        esc(T('رصيد الإجازات', 'Leave balances')), icon('leave', 16, 2), esc(T('رصيدك', 'Your balance')), esc(T('السنة دي · بعد الطلبات المستنية', 'This year · after requests waiting')), cells)


def item(title, sub, when, state, label, extra='', tone='leave', path=None):
    p = ''
    if path:
        p = '<details class="wfo-apv-more"%s><summary>%s</summary><ol class="wfo-apv-path">%s</ol></details>' % (
            ' open' if state == 'waiting' else '', esc(T('مين بيقرر', 'Who decides it')),
            ''.join('<li class="is-%s"><span class="wfo-apv-dot" aria-hidden="true">%s</span><div><b>%s</b><small>%s</small></div></li>' % (s, '✓' if s == 'done' else str(i + 1), esc(T(*w)), esc(T(*sb))) for i, (s, w, sb) in enumerate(path)))
    return ('<article class="wfo-apv-item tone-%s"><div class="wfo-apv-who"><div class="wfo-apv-t"><b>%s</b><small>%s</small></div><span class="wfo-apv-state is-%s">%s</span></div>%s%s</article>') % (
        tone, esc(T(*title)), esc(' · '.join(x for x in (T(*sub) if sub else '', T(*when)) if x)), state, esc(T(*label)), p, extra)


def cancel_box(open_=False):
    return ('<details class="wfo-apv-withdraw wfo-lr-cancel"%s><summary class="wfo-apv-btn is-bad is-sm">%s</summary><form><p>%s</p><p class="wfo-lr-note">%s</p>'
            '<button type="button" class="wfo-apv-btn is-bad">%s</button></form></details>') % (
        ' open' if open_ else '', esc(T('اطلب إلغاء', 'Request cancellation')),
        esc(T('تطلب إلغاء الإجازة دي؟ مديرك هو اللي يوافق، ولما يوافق الأيام ترجع لرصيدك وتتشال من الجدول.',
              'Ask to cancel this leave? Your manager decides; once approved, the days go back to your balance and come off the schedule.')),
        esc(T('مرة واحدة بس، ولحد اليوم اللي قبل الإجازة.', 'Once only, and until the day before the leave starts.')),
        esc(T('أيوه، اطلب الإلغاء', 'Yes, ask to cancel it')))


def back_box(open_=False):
    return ('<details class="wfo-apv-withdraw wfo-lr-cancel"%s><summary class="wfo-apv-btn is-sm wfo-lr-back">%s</summary><form><p>%s</p><p class="wfo-lr-note">%s</p>'
            '<button type="button" class="wfo-apv-btn is-ok">%s</button></form></details>') % (
        ' open' if open_ else '', esc(T('رجعت بدري', "I'm back early")),
        esc(T('هتلغي الأيام من بكرة الأربعاء 25 نوفمبر لحد 27 نوفمبر (3 أيام). مديرك هو اللي يوافق، ولما يوافق الأيام دي ترجع لرصيدك والجدول يرجع زي ما كان.',
              'The days from tomorrow, Wed 25 Nov, to 27 Nov are cancelled (3 days). Your manager decides; once approved they go back to your balance and the schedule is as before.')),
        esc(T('الأيام اللي فاتت لحد النهارده تفضل إجازة. ده بيتحسب طلب الإلغاء الوحيد للإجازة دي.', 'The days up to today stay leave. This is the one cancellation this leave can have.')),
        esc(T('أيوه، رجعت بدري', "Yes, I'm back early")))


def withdraw_box():
    return '<details class="wfo-apv-withdraw"><summary class="wfo-apv-btn is-bad is-sm">%s</summary></details>' % esc(T('اسحب طلب الإلغاء', 'Withdraw the cancellation'))


def note(text, cls=''):
    return '<p class="wfo-lr-meta%s">%s%s</p>' % (cls, icon('alert', 14, 2), esc(T(*text)))


def items(only_leave=False, open_cancel=False, open_back=False):
    path2 = [('done', ('المدير المباشر · أحمد سمير', 'Line manager · Ahmed Samir'), ('وافق الأحد 15 نوفمبر', 'Approved Sun 15 Nov')), ('done', ('الموارد البشرية', 'HR'), ('وافقت الاثنين 16 نوفمبر', 'Approved Mon 16 Nov'))]
    pathc = [('done', ('المدير المباشر · أحمد سمير', 'Line manager · Ahmed Samir'), ('وافق على الإلغاء', 'Approved the cancellation')), ('waiting', ('الموارد البشرية', 'HR'), ('مستنية قرارها', 'Waiting for their decision'))]
    out = [
        item(('إجازة سنوية · 6–10 ديسمبر', 'Annual Leave · 6–10 Dec'), ('5 أيام', '5 days'), ('طلبتها الخميس 12 نوفمبر', 'Asked Thu 12 Nov'), 'approved', ('اتوافق عليها', 'Approved'), cancel_box(open_cancel), path=path2),
        item(('إجازة عارضة · 24 نوفمبر', 'Casual Leave · 24 Nov'), ('طلب إلغاء', 'Cancellation asked'), ('طلبت الإلغاء النهارده', 'Cancellation asked today'), 'waiting', ('الإلغاء مستني · المستوى 2 من 2', 'Cancellation waiting · level 2 of 2'),
             note(('الإجازة لسه قايمة لحد ما الإلغاء يتوافق عليه.', 'The leave stands until the cancellation is approved.')) + withdraw_box(), path=pathc),
    ]
    if not only_leave:
        out.append(item(('عمل إضافي · ساعتين', 'Overtime · 2 h'), ('بعد الشغل', 'After work'), ('الأربعاء 18 نوفمبر', 'Wed 18 Nov'), 'waiting', ('مستني · المستوى 1 من 1', 'Waiting · level 1 of 1'), tone='overtime'))
    out += [
        item(('إجازة مرضية · 22–27 نوفمبر', 'Sick Leave · 22–27 Nov'), ('6 أيام · بدأت', '6 days · started'), ('السبت 21 نوفمبر', 'Sat 21 Nov'), 'approved', ('اتوافق عليها', 'Approved'), back_box(open_back)),
        item(('إجازة سنوية · 2 نوفمبر', 'Annual Leave · 2 Nov'), ('', ''), ('الأحد 25 أكتوبر', 'Sun 25 Oct'), 'approved', ('اتوافق عليها', 'Approved'), note(('اترفض طلب الإلغاء، ومينفعش يتبعت تاني.', 'The cancellation was refused; it cannot be asked again.'))),
        item(('إجازة سنوية · 20–21 أكتوبر', 'Annual Leave · 20–21 Oct'), ('يومين رجعوا لرصيدك', '2 days back to your balance'), ('الخميس 8 أكتوبر', 'Thu 8 Oct'), 'cancelled', ('اتلغت', 'Cancelled')),
    ]
    return ''.join(out)


def filters(active):
    chips = [('', ('الكل', 'All')), ('leave', ('إجازات', 'Leave')), ('waiting', ('مستني · 2', 'Waiting · 2')), ('approved', ('اتوافق عليها · 3', 'Approved · 3')), ('cancelled', ('اتلغت · 1', 'Cancelled · 1'))]
    return '<nav class="wfo-apv-filters">%s</nav>' % ''.join('<a href="#"%s>%s</a>' % (' aria-current="true"' if k == active else '', esc(T(*l))) for k, l in chips)


def page(only_leave=False, open_cancel=False, toast=None, open_back=False):
    def make():
        t = '<div class="wfo-apv-toast is-ok" role="status">%s</div>' % esc(T(*toast)) if toast else ''
        more = ('<a class="wfo-lr-all" href="#">%s</a>' % esc(T('كل تاريخ الإجازات (34 طلب) ←', 'All leave history (34 requests) →'))) if only_leave else ''
        body = ('<div class="wfo-apv is-mine">%s<div class="wfo-apv-bar"><span class="wfo-apv-bar-gap"></span><button type="button" class="wfo-apv-btn is-new">+ %s</button></div>%s%s<div class="wfo-apv-list">%s</div>%s</div>') % (
            t, esc(T('طلب جديد', 'New request')), balances(), filters('leave' if only_leave else ''), items(only_leave, open_cancel, open_back), more)
        return shell(body)
    return make


def build():
    write(F, 'list', page())
    write(F, 'cancel', page(open_cancel=True))
    write(F, 'sent', page(toast=('اتبعت طلب الإلغاء. هنبلغك لما يتقرر.', 'Cancellation asked. You will be told once it is decided.')))
    write(F, 'leave', page(only_leave=True))
    write(F, 'back', page(open_back=True))
    gallery(F, 'Leave in My requests — mockups (Step 1)',
            '<p>The owner\'s idea (2026-11-21): the Leave page is no longer needed. What only it does moves to <b>My requests</b>: '
            'a <b>balances strip</b> (every leave type), <b>"Request cancellation"</b> on an approved leave that has not started, the cancellation\'s '
            'states, and a <b>Leave</b> filter with the whole history. Leave and early leave are already asked from <b>New request</b>; managers already '
            'decide them in <b>My approvals</b>.</p><p>The old Leave page stays behind a switch (wp-admin → Features), <b>shown until it is turned off</b>. '
            'Only while My approvals is on.</p>',
            [('My requests', None, [('list', 'Balances on top; an approved leave can be cancelled; a cancellation waiting; one started; one refused; one cancelled', 'desk'),
                                    ('cancel', '"Request cancellation" opened: what happens, once only', 'phone'),
                                    ('sent', 'After asking: the message', 'phone'),
                                    ('leave', 'The Leave filter: leave only, and the whole history', 'desk'),
                                    ('back', '"I\'m back early" on a leave that has started: the days from tomorrow on, with the same approval', 'phone')])])


if __name__ == '__main__':
    build()
