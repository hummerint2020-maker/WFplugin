"""Schedule planner (owner request 2026-11-28: "AI that makes the schedule, but free"; decisions in docs/tasks/schedule-planner.md).

A planner built into the plugin (no external AI, nothing leaves the site) fills the EMPTY days of a week on the Attendance grid,
following the office minimum, each person's office days a week (a default, overridden per employee), a fair rotation and the
days each employee prefers. It makes a draft: nothing is saved until the administrator presses Apply. Days it could not get
right are listed. Administrators only. Off by default.

The Attendance screens start from the real page's snapshot (snap/attendance.<lang>.html), like the office minimum mockups.
"""
import os, re
from build import T, Lang, esc, icon, app_page, admin_page, write, gallery, Build, KIT
import screens_office as OM
import build as _build

_build.ICONS.setdefault('spark', '<path d="M12 3l1.8 4.6L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.9z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>')

F = 'schedule-planner'
CSS = 'schedule-planner.css'
SUB = [('emp', ('الموظفون', 'Employees')), ('teams', ('الفرق', 'Teams')), ('sched', ('إعدادات الجدول', 'Schedule Configuration')),
       ('features', ('إعدادات الميزات', 'Feature Configuration'))]


def snap():
    h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
    h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
    css = CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS
    return h.replace('</head>', '<link rel="stylesheet" href="%s"><link rel="stylesheet" href="%smock.css"></head>' % (css, Build.kit), 1)


def bar():
    return ('<section class="wfo-sp-bar"><div class="wfo-sp-bar-text"><b>%s</b><span>%s</span></div>'
            '<button type="button" class="ews-btn wfo-sp-go">%s %s</button></section>') % (
        esc(T('خطّط الأسبوع', 'Plan the week')),
        esc(T('بيملى الأيام الفاضية بس (مكتب أو من البيت) حسب الحد الأدنى، أيام المكتب لكل واحد، التوزيع العادل والأيام المفضلة. هتشوفه مسودة قبل الحفظ.',
              'Fills the empty days only (Office or WFH) by the office minimum, each person\'s office days, a fair rotation and preferred days. You see a draft before anything is saved.')),
        icon('spark', 16, 2), esc(T('خطّط الأسبوع', 'Plan the week')))


def draft_panel():
    warns = [
        (T('الخميس ١٥', 'Thu 15'), T('٩ في المكتب، والحد الأدنى ١٢: ٤ في إجازة و٣ وصلوا لأيام مكتبهم في الأسبوع.', '9 in the office, the minimum is 12: 4 on leave and 3 have reached their office days this week.')),
        (T('الأحد ١١', 'Sun 11'), T('مديرين في المكتب ١، والحد الأدنى ٢: المدير التاني في رحلة عمل.', '1 manager in the office, the minimum is 2: the other is on a business trip.')),
        (T('كريم فتحي', 'Karim Fathy'), T('مكتب ٤ أيام بدل ٣: عشان الحد الأدنى يوم الاثنين.', 'Office 4 days instead of 3: to reach Monday\'s minimum.')),
    ]
    rows = ''.join('<li><b>%s:</b> <span>%s</span></li>' % (esc(a), esc(b)) for a, b in warns)
    return ('<section class="wfo-sp-draft" role="region" aria-label="%s"><div class="wfo-sp-draft-head">%s<div><b>%s</b><span>%s</span></div>'
            '<div class="wfo-sp-actions"><button type="button" class="ews-btn ews-btn-primary">%s</button><button type="button" class="ews-btn">%s</button><button type="button" class="ews-btn ews-btn-ghost">%s</button></div></div>'
            '<div class="wfo-sp-stats"><span><b>%s</b>%s</span><span><b>%s</b>%s</span><span><b>%s</b>%s</span><span><b>%s</b>%s</span></div>'
            '<details class="wfo-sp-warn" open><summary>%s</summary><ul>%s</ul></details></section>') % (
        esc(T('مسودة الجدول', 'Draft schedule')), icon('spark', 20, 2), esc(T('مسودة: لسه متحفظتش', 'Draft: nothing saved yet')),
        esc(T('الأيام المقترحة عليها خط متقطع. غيّر أي يوم بإيدك قبل التطبيق.', 'Proposed days have a dashed outline. Change any day by hand before applying.')),
        esc(T('تطبيق المسودة', 'Apply the draft')), esc(T('خطّط تاني', 'Plan again')), esc(T('إلغاء', 'Discard')),
        T('٦٢', '62'), esc(T(' يوم مقترح', ' days proposed')), T('٣٨', '38'), esc(T(' مكتب', ' office')), T('٢٤', '24'), esc(T(' من البيت', ' WFH')),
        T('٨٧٪', '87%'), esc(T(' من الأيام المفضلة اتحققت', ' of preferred days met')),
        esc(T('٣ حاجات مقدرش يحققها', '3 things it could not meet')), rows)


def mark_draft(h):
    """Proposed days: a dashed outline and a small spark (the mockup marks the Office/WFH days of the first rows)."""
    n = [0]
    def sub(m):
        if n[0] >= 62 or 'data-planned="Vacation"' in m.group(0) or 'Business Trip' in m.group(0):
            return m.group(0)
        n[0] += 1
        return m.group(0).replace('class="ews-att-day ', 'class="ews-att-day wfo-sp-proposed ', 1)
    return re.sub(r'<td class="ews-att-day ews-plan-(?:wfh|office)"[^>]*>', sub, h)


def attendance(variant):
    """variant: start (the Plan the week bar) | draft (the draft, its numbers and what it could not meet)"""
    def make():
        h = snap()
        add = bar() if variant == 'start' else draft_panel()
        h = h.replace('<form method="post" action="#/wp-admin/admin-post.php" id="ews-grid-form">', add + '<form method="post" action="#/wp-admin/admin-post.php" id="ews-grid-form">', 1)
        if variant == 'draft':
            h = mark_draft(h)
            h = h.replace('<div class="ews-att-plan-legend">', '<div class="ews-att-plan-legend"><span><i class="wfo-sp-key"></i> %s</span>' % esc(T('مقترح (مسودة)', 'Proposed (draft)')), 1)
        return h
    return make


def prefs():
    """The employee, in the app (My Profile): the days they prefer the office or home."""
    def make():
        days = [(T('الأحد', 'Sunday'), 'office'), (T('الاثنين', 'Monday'), 'home'), (T('الثلاثاء', 'Tuesday'), ''), (T('الأربعاء', 'Wednesday'), 'office'), (T('الخميس', 'Thursday'), 'home')]
        def seg(cur):
            return '<span class="wfo-sp-seg">%s</span>' % ''.join('<button type="button" class="%s">%s</button>' % ('is-on' if cur == k else '', esc(T(*l))) for k, l in [
                ('office', ('مكتب', 'Office')), ('home', ('البيت', 'Home')), ('', ('مش فارق', 'Either'))])
        rows = ''.join('<li><span>%s</span>%s</li>' % (esc(d), seg(c)) for d, c in days)
        body = ('<section class="wfo-card wfo-sp-prefs"><h2>%s</h2><p>%s</p><ul>%s</ul><p class="wfo-sp-note">%s</p><button type="button" class="ews-btn ews-btn-primary">%s</button></section>') % (
            esc(T('أيامي المفضلة', 'My preferred days')),
            esc(T('اختار الأيام اللي تحب تكون فيها في المكتب أو في البيت. المخطط بيحاول يمشي عليها لما يعمل الجدول.', 'Choose the days you prefer to be in the office or at home. The planner tries to follow them when it makes the schedule.')),
            rows, esc(T('أيام مكتبك في الأسبوع: ٣. الحد الأدنى في المكتب وأي إجازة ليهم الأولوية.', 'Your office days a week: 3. The office minimum and any leave come first.')),
            esc(T('حفظ', 'Save')))
        return app_page(T('ملفي الشخصي', 'My Profile'), body, active='profile', feature_css=CSS)
    return make


def admin_employee():
    """The administrator, on an employee's profile: a different number of office days."""
    def make():
        body = ('<h1>%s</h1><div class="wfo-sp-admin"><h2>%s</h2><table class="form-table" role="presentation">'
                '<tr><th scope="row">%s</th><td><label><input type="radio" name="od"> %s</label><br><label><input type="radio" name="od" checked> %s <input type="number" class="small-text" value="5" min="0" max="7"> %s</label>'
                '<p class="description">%s</p></td></tr>'
                '<tr><th scope="row">%s</th><td>%s<p class="description">%s</p></td></tr></table></div>') % (
            esc(T('تعديل موظف: كريم فتحي', 'Edit employee: Karim Fathy')), esc(T('مخطط الجدول', 'Schedule planner')),
            esc(T('أيام المكتب في الأسبوع', 'Office days a week')), esc(T('زي الشركة (٣)', 'Company default (3)')), esc(T('عدد مختلف:', 'A different number:')), esc(T('أيام', 'days')),
            esc(T('مثلًا المديرين ٥ أيام. الإجازات والعطلات بتتشال من العدد في أسبوعها.', 'For example managers 5 days. Leave and holidays come off the number in their week.')),
            esc(T('أيامه المفضلة', 'Preferred days')), esc(T('الأحد مكتب · الاثنين البيت · الأربعاء مكتب · الخميس البيت', 'Sun office · Mon home · Wed office · Thu home')),
            esc(T('الموظف بيختارها من التطبيق.', 'The employee sets these in the app.')))
        return admin_page(T('الموظفون', 'Employees'), body, SUB, 'emp', feature_css=CSS)
    return make


def features():
    def make():
        body = ('<h1>%s</h1><div class="wfo-sp-admin"><h2>%s <span class="wfo-sp-off">%s</span></h2><p>%s</p><p><label><input type="checkbox"> %s</label></p>'
                '<table class="form-table" role="presentation"><tr><th scope="row">%s</th><td><input type="number" class="small-text" value="3" min="0" max="7"> %s<p class="description">%s</p></td></tr>'
                '<tr><th scope="row">%s</th><td><label><input type="checkbox" checked> %s</label><br><label><input type="checkbox" checked> %s</label></td></tr></table>'
                '<p class="description">%s</p></div>') % (
            esc(T('إعدادات الميزات', 'Feature Configuration')), esc(T('مخطط الجدول', 'Schedule planner')), esc(T('مقفول', 'Off')),
            esc(T('زرار «خطّط الأسبوع» في صفحة الحضور للمسؤولين: بيملى الأيام الفاضية بمكتب أو من البيت، ويعرض مسودة قبل الحفظ. بيشتغل على السيرفر بتاعك: ببلاش، ومفيش بيانات بتطلع برّه الموقع.',
                  'A "Plan the week" button on the Attendance page for administrators: fills the empty days with Office or WFH and shows a draft before saving. It runs on your own server: free, and no data leaves the site.')),
            esc(T('تشغيل مخطط الجدول', 'Turn the schedule planner on')),
            esc(T('أيام المكتب في الأسبوع (افتراضي)', 'Office days a week (default)')), esc(T('أيام', 'days')),
            esc(T('ممكن تغيّره لأي موظف من صفحته.', 'Can be changed for any employee on their profile.')),
            esc(T('يراعي', 'Takes into account')), esc(T('الأيام المفضلة (الموظف بيختارها من التطبيق)', 'Preferred days (employees choose them in the app)')),
            esc(T('التوزيع العادل: محدش ياخد نفس الأيام كل أسبوع', 'Fair rotation: nobody gets the same days every week')),
            esc(T('الحد الأدنى في المكتب بيتاخد من إعداداته. الإجازات والعطلات والأسابيع المقفولة عمرها ما بتتغير.', 'The office minimum comes from its own settings. Leave, holidays and locked weeks are never changed.')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUB, 'features', feature_css=CSS)
    return make


def build():
    for slug, make in [('attendance', attendance('start')), ('draft', attendance('draft')), ('prefs', prefs()), ('employee', admin_employee()), ('features', features())]:
        write(F, slug, make)
    D, P = 'desk', 'phone'
    gallery(F, 'Schedule planner — mockups', (
        '<p><b>Owner request (2026-11-28):</b> "AI that makes the schedule, but free". <b>Decisions:</b> a planner built into the plugin '
        '(free, nothing leaves the site) fills the <b>empty</b> days of a week following the office minimum, each person&#39;s office days, a '
        'fair rotation and preferred days; a <b>draft</b> the administrator reviews, then Apply; what it could not meet is listed; '
        'administrators only; off by default.</p>'), [
        ('The Attendance page (administrator)', '', [('attendance', '"Plan the week" above the grid', D), ('draft', 'The draft: proposed days, the numbers, what it could not meet', D)]),
        ('Settings', '', [('features', 'Features: the switch (off), the default office days, preferences and rotation', D),
                          ('employee', 'An employee\'s profile: a different number of office days', D)]),
        ('The employee', '', [('prefs', 'My Profile: my preferred days', P)]),
    ])
