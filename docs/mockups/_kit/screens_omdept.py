"""Office minimum by department (owner request 2026-11-18): "each department has its own nature". Owner answer: each department
can have a full copy of the Office minimum settings (on/off, minimum, days, statuses, team shares, managers, reminder); a
department without its own settings works with the company's, as today. Its managers are the team managers in that department. Each person sees their own
department; the administrator sees the company and can pick a department. A release after 3.31.153.

The screens start from the Manager office minimum mockups (screens_mgrmin.py), on the real Attendance page."""
import re
from build import T, Lang, esc, icon, write, gallery, admin_page
import screens_office as OM
import screens_mgrmin as MM

F = 'office-minimum-departments'
CSS = 'office-minimum-departments.css'
DEPTS = [(('المبيعات', 'Sales'), 22, '4', '1'), (('العمليات', 'Operations'), 12, '6', '2'), (('الحسابات', 'Finance'), 6, '', ''), (('الموارد البشرية', 'HR'), 4, '', '')]


def scoped(make, office, office_min, managers, mgr_min):
    """Runs a screens_mgrmin page with a department's numbers, then puts the module back."""
    keep = (OM.OFFICE, OM.MIN, MM.MANAGERS, MM.MGR_MIN)
    OM.OFFICE, OM.MIN, MM.MANAGERS, MM.MGR_MIN = office, office_min, managers, mgr_min
    try:
        return make()
    finally:
        OM.OFFICE, OM.MIN, MM.MANAGERS, MM.MGR_MIN = keep


def scope_bar(name, people, mgrs, admin=False, picked=None):
    pick = ''
    if admin:
        opts = ''.join('<option%s>%s</option>' % (' selected' if T(*d[0]) == picked else '', esc(T(*d[0]))) for d in DEPTS[:2])
        pick = ('<label class="wfo-omd-pick">%s <select><option%s>%s</option>%s</select></label>') % (
            esc(T('الحد الأدنى لـ', 'Minimum for')), '' if picked else ' selected', esc(T('الشركة كلها', 'The whole company')), opts)
    return ('<div class="wfo-omd-bar">%s<span class="wfo-omd-what"><b>%s</b> %s</span>%s</div>') % (
        icon('office', 16, 2.2), esc(name), esc(T('· %s في المكتب · %s مديرين' % (people, mgrs), '· %s in the office · %s managers' % (people, mgrs))), pick)


def attendance(variant):
    """variant: dept (a Sales manager) | admin (the administrator picked Sales) | company (the administrator, the whole company)"""
    def make():
        sales = [MM.MANAGERS[0]]
        if variant == 'company':
            h = MM.attendance('week')()
            bar = scope_bar(T('الشركة كلها', 'The whole company'), OM.MIN, MM.MGR_MIN, admin=True)
        else:
            h = scoped(MM.attendance('week'), [5, 4, 2, 4, 3, None, None], 4, sales, 1)
            bar = scope_bar(T('إدارة المبيعات', 'Sales department'), 4, 1, admin=variant == 'admin', picked=T('المبيعات', 'Sales'))
            h = h.replace(esc(T('%d أيام تحت الحد الأدنى' % 3, '3 days below a minimum')), esc(T('المبيعات: 3 أيام تحت الحد الأدنى', 'Sales: 3 days below its minimum')), 1)
        h = h.replace('</head>', '<link rel="stylesheet" href="%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
        for c in ('manager-office-minimum.css', 'manager-office-minimum-rtl.css'):
            h = h.replace('href="%s"' % c, 'href="../manager-office-minimum/%s"' % c)
        h = h.replace('<div class="ews-day-quick-actions">', bar + '<div class="ews-day-quick-actions">', 1)
        return h
    return make


def admin_settings(dept=True):
    """dept=True: the Sales tab (its own settings); False: the company tab, as today."""
    def make():
        h = MM.admin_settings()()
        for c in ('manager-office-minimum.css', 'manager-office-minimum-rtl.css'):
            h = h.replace('href="%s"' % c, 'href="../manager-office-minimum/%s"' % c)
        tabs = [(('الشركة كلها', 'The whole company'), ''), (('المبيعات', 'Sales'), T('إعداداتها', 'own settings')), (('العمليات', 'Operations'), T('إعداداتها', 'own settings')),
                (('الحسابات', 'Finance'), T('زي الشركة', 'as the company')), (('الموارد البشرية', 'HR'), T('زي الشركة', 'as the company'))]
        sel = 1 if dept else 0
        bar = '<nav class="wfo-omd-tabs" aria-label="%s">%s</nav>' % (esc(T('الإدارات', 'Departments')), ''.join(
            '<a href="#" class="%s">%s%s</a>' % ('is-on' if k == sel else '', esc(T(*n)), ('<small>%s</small>' % esc(t)) if t else '') for k, (n, t) in enumerate(tabs)))
        h = h.replace('<div class="wfo-om-admin">', bar + '<div class="wfo-om-admin">', 1)
        if dept:
            own = ('<div class="wfo-omd-own"><label><input type="checkbox" checked> <b>%s</b></label><p class="description">%s</p></div>') % (
                esc(T('المبيعات ليها إعداداتها الخاصة', 'Sales has its own settings')),
                esc(T('مقفولة = المبيعات بتشتغل بإعدادات الشركة. مفتوحة = الإعدادات اللي تحت للمبيعات بس: موظفيها وفرقها ومديرينها، ومدير المبيعات بيشوف أرقامها.',
                      'Off = Sales works with the company\'s settings. On = the settings below are for Sales only: its people, its teams and its managers; the Sales manager sees its numbers.')))
            h = h.replace('<div class="wfo-om-admin">', own + '<div class="wfo-om-admin">', 1)
            h = h.replace('value="12"', 'value="4"').replace('placeholder="12"', 'placeholder="4"').replace(' value="8"', '')
            for a, b in [(('المبيعات', 'Sales'), ('مبيعات القاهرة', 'Sales Cairo')), (('خدمة العملاء', 'Customer Service'), ('مبيعات إسكندرية', 'Sales Alex'))]:
                h = h.replace('<td>%s</td>' % esc(T(*a)), '<td>%s</td>' % esc(T(*b)))
            h = re.sub(r'<tr><td>%s</td>.*?</tr>' % re.escape(esc(T('الحسابات', 'Accounts'))), '', h, count=1, flags=re.S)
            h = re.sub(r'<tr><td>%s</td>.*?</tr>' % re.escape(esc(T('تكنولوجيا المعلومات', 'IT'))), '', h, count=1, flags=re.S)
            h = h.replace('<td>12</td><td>4</td>', '<td>14</td><td>3</td>', 1).replace('<td>10</td><td>3</td>', '<td>8</td><td>1</td>', 1)
            h = h.replace(esc(T('مديرين الفرق (4): عادل حمدي، هشام فتحي، ريم نبيل، عمرو فتحي', 'The team managers (4): Adel Hamdy, Hesham Fathy, Reem Nabil, Amr Fathy')),
                          esc(T('مديرين فرق المبيعات (2): عادل حمدي، هشام فتحي', 'Sales\'s team managers (2): Adel Hamdy, Hesham Fathy')))
            h = h.replace('value="2">', 'value="1">', 1).replace('placeholder="2"', 'placeholder="1"')
        return h.replace('</head>', '<link rel="stylesheet" href="../office-minimum-departments/%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
    return make


def build():
    for slug, make in [('dept', attendance('dept')), ('admin', attendance('admin')), ('company', attendance('company')), ('phone', attendance('dept')), ('settings', admin_settings(True)), ('settings-company', admin_settings(False))]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Office minimum by department — mockups (Step 1)', (
        '<p>Each department can have its own minimum of people and of managers in the office, checked on its own. The company\'s minimum stays as it is; '
        'a department without a number has no check of its own. A department manager sees their department\'s numbers; the administrator sees the company and can pick a department. '
        'Only a warning, off by default (a department needs a number).</p>'), [
        ('App → Attendance', 'Sales: 4 people and 1 manager in the office each day.', [
            ('dept', 'The Sales manager: the counters and the card are Sales\'s', D), ('phone', 'The same on a phone', P),
            ('admin', 'The administrator picked Sales', D), ('company', 'The administrator: the whole company (as today)', D)]),
        ('wp-admin', 'Feature Configuration → Office Minimum: a tab per department. A department with its own settings has the whole form for itself; the others use the company\'s.', [
            ('settings', 'The Sales tab: Sales has its own settings (its minimum, days, statuses, teams, managers, reminder)', D),
            ('settings-company', 'The company tab: as today', D)]),
    ])
