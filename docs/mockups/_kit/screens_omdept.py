"""Office minimum by department (owner request 2026-11-18): "each department has its own nature". Owner answers: the company's
minimum stays as it is, and a department may have its own minimum (people and managers), checked on its own; a department
without a number has no check of its own. Its managers are the team managers in that department. Each person sees their own
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


def admin_settings():
    def make():
        h = MM.admin_settings()()
        for c in ('manager-office-minimum.css', 'manager-office-minimum-rtl.css'):
            h = h.replace('href="%s"' % c, 'href="../manager-office-minimum/%s"' % c)
        rows = ''.join(('<tr><td>%s</td><td>%d</td><td><input type="number" min="0" class="small-text" placeholder="%s" value="%s"></td>'
                        '<td><input type="number" min="0" class="small-text" placeholder="%s" value="%s"></td></tr>') % (
            esc(T(*n)), size, esc(T('—', '—')), p, esc(T('—', '—')), m) for n, size, p, m in DEPTS)
        teams = [(('مبيعات القاهرة', 'Sales Cairo'), 14, 3, ''), (('مبيعات إسكندرية', 'Sales Alex'), 8, 1, '2')]
        sub = ''.join('<tr><td>%s</td><td>%d</td><td>%d</td><td><input type="number" min="0" class="small-text" placeholder="%s" value="%s"></td></tr>' % (
            esc(T(*n)), size, auto, esc(T('تلقائي', 'Auto')), fixed) for n, size, auto, fixed in teams)
        rows = rows.replace('</tr>', ('</tr><tr class="wfo-omd-teams-row"><td colspan="4"><details open><summary>%s</summary>'
                                      '<table class="wfo-om-share wfo-omd-sub"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>'
                                      '<p class="description">%s</p></details></td></tr>') % (
            esc(T('نصيب فرق المبيعات من الـ 4', 'Sales teams\' share of the 4')), esc(T('الفريق', 'Team')), esc(T('العدد', 'People')), esc(T('النصيب التلقائي', 'Automatic share')),
            esc(T('رقم ثابت', 'Fixed number')), sub, esc(T('تلقائي بالنسبة لعدد الفريق جوه الإدارة؛ الرقم الثابت هنا للإدارة دي بس.', 'Automatic by team size inside the department; a fixed number here is for this department only.'))), 1)
        block = ('<div class="wfo-omd-admin"><h3>%s</h3><p class="description">%s</p>'
                 '<table class="widefat striped wfo-omd-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>'
                 '<p class="description">%s</p></div>') % (
            esc(T('لكل إدارة حدها', 'Each department\'s own minimum')),
            esc(T('اختياري. الإدارة اللي ليها رقم بتتشيك لوحدها، بنفس الحالات والتذكير. الحد الأدنى للشركة فوق زي ما هو.',
                  'Optional. A department with a number is checked on its own, with the same statuses and reminder. The company\'s minimum above stays as it is.')),
            esc(T('الإدارة', 'Department')), esc(T('الموظفين', 'People')), esc(T('في المكتب كل يوم', 'In the office each day')), esc(T('مديرين كل يوم', 'Managers each day')), rows,
            esc(T('فاضي = مفيش حد أدنى خاص بالإدارة. المديرين = مديرين الفرق اللي في الإدارة. مدير الإدارة بيشوف أرقام إدارته بس في صفحة الحضور.',
                  'Empty = no minimum of its own. Its managers = the team managers in the department. A department manager sees only their department\'s numbers on the Attendance page.')))
        return h.replace('</table></div></div><p class="submit">', '</table></div>' + block + '</div><p class="submit">', 1).replace(
            '</head>', '<link rel="stylesheet" href="../office-minimum-departments/%s"></head>' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS), 1)
    return make


def build():
    for slug, make in [('dept', attendance('dept')), ('admin', attendance('admin')), ('company', attendance('company')), ('phone', attendance('dept')), ('settings', admin_settings())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Office minimum by department — mockups (Step 1)', (
        '<p>Each department can have its own minimum of people and of managers in the office, checked on its own. The company\'s minimum stays as it is; '
        'a department without a number has no check of its own. A department manager sees their department\'s numbers; the administrator sees the company and can pick a department. '
        'Only a warning, off by default (a department needs a number).</p>'), [
        ('App → Attendance', 'Sales: 4 people and 1 manager in the office each day.', [
            ('dept', 'The Sales manager: the counters and the card are Sales\'s', D), ('phone', 'The same on a phone', P),
            ('admin', 'The administrator picked Sales', D), ('company', 'The administrator: the whole company (as today)', D)]),
        ('wp-admin', '', [('settings', 'Feature Configuration → Office Minimum: each department\'s own minimum', D)]),
    ])
