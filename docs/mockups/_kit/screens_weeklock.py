"""Week lock (docs/tasks/week-lock.md, Step 1): from a weekly cutoff (here Thursday 17:00, site time) the
coming week's schedule is final and stays final. Only people with the new permission "Edit a locked week"
can change it (every change is audited). Leave, leave cancellations and swaps are still requested,
approved and applied automatically (owner decision 4). A manager's change on a locked day is refused
with a message in release 1; release 2 turns it into a request to HR.

The Attendance page screens start from the real page (snap/attendance.<lang>.html: next week, 11 – 15 Oct)
and add the new parts; everything else uses the kit's real frames.
"""
import os, re
from build import T, Lang, esc, icon, app_page, admin_page, page_doc, ux_modal, write, gallery, Build, KIT

F = 'week-lock'
CSS = 'week-lock.css'
WEEK = ('الأحد 11 – الخميس 15 أكتوبر', 'Sun 11 – Thu 15 Oct')
CUTOFF = ('الخميس 8 أكتوبر 5:00 م', 'Thu 8 Oct, 5:00 PM')


def banner(kind):
    """kind: soon | locked | override"""
    if kind == 'soon':
        ico, title, text = 'clock', T('الأسبوع ده هيبقى نهائي النهارده الساعة 5:00 م', 'This week becomes final today at 5:00 PM'), T(
            'بعد الميعاد ده الجدول ما يتغيّرش غير من اللي معاه صلاحية «تعديل أسبوع مقفول». الإجازات والتبديلات بتتطبّق عادي.',
            'After that, only people with "Edit a locked week" can change it. Leave and swaps are still applied as usual.')
        side = '<span class="wfo-wl-when">%s<b dir="ltr">4:52</b></span>' % esc(T('فاضل', 'Time left'))
    elif kind == 'locked':
        ico, title, text = 'lock', T('الأسبوع ده نهائي', 'This week is final'), T(
            'اتقفل %s. الإجازات والتبديلات اللي بتتوافق بتتطبّق عادي. لو محتاج تغيّر يوم، كلّم HR.' % T(*CUTOFF),
            'Locked since %s. Approved leave and swaps are still applied. To change a day, ask HR.' % T(*CUTOFF))
        side = '<span class="wfo-wl-final">%s%s</span>' % (icon('lock', 14, 2.2), esc(T('نهائي', 'Final')))
    else:
        ico, title, text = 'lock', T('الأسبوع ده نهائي، وانت تقدر تعدّله', 'This week is final, and you can still edit it'), T(
            'اتقفل %s. أي تغيير بتعمله بيتسجّل في سجل التدقيق باسمك، والموظف بيوصله إشعار بيومه الجديد.' % T(*CUTOFF),
            'Locked since %s. Every change you make is recorded in the Audit Log under your name, and the employee is told about the new day.' % T(*CUTOFF))
        side = '<span class="wfo-wl-final is-override">%s%s</span>' % (icon('key', 14, 2.2), esc(T('تعديل أسبوع مقفول', 'Edit a locked week')))
    return ('<section class="wfo-wl-banner is-%s" role="status"><span class="wfo-wl-ico">%s</span><div class="wfo-wl-text"><h3>%s</h3><p>%s</p></div>%s</section>') % (
        kind, icon(ico, 20, 2.2), esc(title), esc(text), side)


# ---------------------------------------------------------------- the Attendance page (from the real page)

def attendance(variant):
    """variant: soon | locked | refused | override"""
    def make():
        h = open(os.path.join(KIT, 'snap', 'attendance.%s.html' % Lang.code)).read()
        h = h.replace('{{ASSETS}}', Build.assets).replace('BA Team', T('شركة النيل للمقاولات', 'Nile Contracting'))
        feat = '<link rel="stylesheet" href="%s">' % (CSS.replace('.css', '-rtl.css') if Lang.rtl() else CSS)
        h = h.replace('</head>', feat + '<link rel="stylesheet" href="%smock.css"></head>' % Build.kit, 1)
        kind = 'locked' if variant in ('locked', 'refused') else variant
        if variant != 'refused':
            h = h.replace('<section class="ews-att-controls', banner(kind) + '<section class="ews-att-controls', 1)
        # the week in the week picker carries the mark
        if variant in ('locked', 'override', 'refused'):
            h = re.sub(r'(<strong dir="ltr">[^<]*</strong>)', lambda m: m.group(1) + '<span class="wfo-wl-final is-small">%s%s</span>' % (icon('lock', 12, 2.4), esc(T('نهائي', 'Final'))), h, count=1)
        if variant == 'locked':
            h = h.replace('class="ews-attendance-page wfo-att"', 'class="ews-attendance-page wfo-att wfo-wl-locked"', 1)
            # every day is read-only: no picker, a lock instead
            h = re.sub(r'<button type="button" class="wfo-attg-chip" data-ews-cell aria-haspopup="dialog" (data-title="[^"]*")>',
                       lambda m: '<button type="button" class="wfo-attg-chip" disabled aria-disabled="true" %s>' % m.group(1), h)
            h = h.replace('</b></button>', '</b><span class="wfo-wl-cell-lock">%s</span></button>' % icon('lock', 12, 2.4))
            h = re.sub(r'<select id="wfo-fill-(\d)"', lambda m: '<select disabled id="wfo-fill-%s"' % m.group(1), h)
            # the save bar says why there is nothing to save
            h = re.sub(r'<div class="ews-att-savebar">.*?</form>', lambda m: (
                '<div class="ews-att-savebar wfo-wl-savebar">%s<span>%s</span></div>\n    </form>') % (
                    icon('lock', 16, 2.2), esc(T('الأسبوع نهائي: مفيش تغييرات تتحفظ. الأسبوع الجاي (18 – 22 أكتوبر) لسه مفتوح.',
                                                 'The week is final: there is nothing to save. Next week (18 – 22 Oct) is still open.'))), h, count=1, flags=re.S)
            # the Excel / CSV import refuses rows of a locked week (shown in its preview)
            h = h.replace('<div class="ews-csv-inside">', '<div class="ews-csv-inside"><p class="wfo-wl-csv">%s%s</p>' % (
                icon('lock', 14, 2.2), esc(T('صفوف الأسابيع النهائية بتظهر في المعاينة كأخطاء ومش بتتحفظ.', 'Rows in a final week show as errors in the preview and are not saved.'))), 1)
        if variant == 'refused':
            h = h.replace('</body>', ux_modal('error', T('3 تغييرات ما اتحفظتش', '3 changes were not saved'), T(
                'الأسبوع %s بقى نهائي %s وانت بتعدّل. باقي التغييرات اتحفظت. لو محتاج التغييرات دي، كلّم HR.' % (T(*WEEK), T(*CUTOFF)),
                'The week of %s became final at %s while you were editing. Your other changes were saved. If you need these, ask HR.' % (T(*WEEK), T(*CUTOFF)))) + '</body>', 1)
        return h
    return make


# ---------------------------------------------------------------- the employee

DAYS = [('أحد', 'Sun', '11', 'office', ('مكتب', 'Office')), ('اثنين', 'Mon', '12', 'wfh', ('من البيت', 'WFH')), ('ثلاثاء', 'Tue', '13', 'office', ('مكتب', 'Office')),
        ('أربعاء', 'Wed', '14', 'leave', ('إجازة', 'Leave')), ('خميس', 'Thu', '15', 'wfh', ('من البيت', 'WFH'))]


def emp_week():
    def make():
        days = ''.join('<li class="is-%s"><span class="wfo-myweek-day">%s</span><span class="wfo-myweek-num">%s</span><span class="wfo-myweek-icon">%s</span><span class="wfo-myweek-word">%s</span></li>' % (
            tone, esc(T(a, e)), n, icon({'office': 'office', 'wfh': 'wfh', 'leave': 'leave'}[tone], 18, 2), esc(T(*w))) for a, e, n, tone, w in DAYS)
        nav = ('<section class="wfo-sched-top"><div class="ews-week-nav wfo-weeknav"><a href="#" class="wfo-weeknav-prev">%s</a>'
               '<div class="wfo-weeknav-text"><span>%s</span><small dir="ltr">11 – 15 Oct 2026</small><em class="wfo-wl-final is-small">%s%s</em></div>'
               '<a href="#" class="wfo-weeknav-next">%s</a></div></section>') % (
                   icon('chevron', 20, 2.2), esc(T('الأسبوع الجاي', 'Next week')), icon('lock', 12, 2.4), esc(T('نهائي', 'Final')), icon('chevron', 20, 2.2))
        card = ('<section class="wfo-sched-card wfo-myweek"><div class="wfo-myweek-head"><span class="ews-att-avatar wfo-att-avatar tone-2" aria-hidden="true">%s</span>'
                '<div class="wfo-myweek-who"><h3>%s</h3><strong>%s</strong></div></div><ol class="wfo-myweek-days">%s</ol>'
                '<p class="wfo-wl-emp-note">%s%s</p>'
                '<button type="button" class="ews-btn wfo-btn-solid wfo-myweek-swap">%s%s</button></section>') % (
                    esc(T('من', 'MA')), esc(T('أسبوعي', 'My week')), esc(T('منى عادل', 'Mona Adel')), days,
                    icon('lock', 14, 2.2), esc(T('جدول الأسبوع ده نهائي من %s. تقدر تطلب إجازة أو تبديل عادي.' % T(*CUTOFF),
                                                 'This week\'s schedule is final since %s. You can still request leave or a swap.' % T(*CUTOFF))),
                    icon('swap', 16), esc(T('طلب تبديل', 'Request a swap')))
        return app_page(T('الجدول', 'Schedule'), '<div class="ews-schedule-page wfo-sched">%s%s</div>' % (nav, card), active='schedule',
                        css_files=['app-schedule.css', 'app-attendance.css'], feature_css=CSS)
    return make


def emp_push():
    def make():
        body = ('<div class="mock-lock"><div class="mock-lock-time" dir="ltr">5:00</div><div class="mock-lock-date">%s</div>'
                '<div class="mock-push"><span class="mock-push-icon">WO</span><div class="mock-push-body"><div class="mock-push-top"><span>WORKFORCE ONE</span><span>%s</span></div>'
                '<strong>%s</strong>%s</div></div><p class="mock-caption">%s</p></div>') % (
                    esc(T('الخميس 8 أكتوبر', 'Thursday 8 October')), esc(T('الآن', 'now')),
                    esc(T('جدول الأسبوع الجاي بقى نهائي', 'Next week\'s schedule is final')),
                    esc(T('الأحد مكتب · الاثنين من البيت · الثلاثاء مكتب · الأربعاء إجازة · الخميس من البيت', 'Sun Office · Mon WFH · Tue Office · Wed Leave · Thu WFH')),
                    esc(T('يوصل وقت القفل لكل موظف عنده جدول في الأسبوع ده (لو الإشعارات شغّالة)، وبيفتح صفحة الجدول. نفس الإشعار بيظهر جوه التطبيق.',
                          'Sent at the cutoff to everyone with a schedule that week (when notifications are on); it opens the Schedule page. The same note shows in the app\'s Notifications.')))
        return page_doc(T('إشعار القفل', 'Cutoff push'), '<link rel="stylesheet" href="%smock.css">' % Build.kit, '<body style="margin:0">%s</body>' % body)
    return make


# ---------------------------------------------------------------- wp-admin

SUBMENU = [('emp', ('الموظفون', 'Employees')), ('sched', ('إعدادات الجدول', 'Schedule Configuration')),
           ('features', ('إعدادات الميزات', 'Feature Configuration')), ('roles', ('الأدوار والصلاحيات', 'Roles & Permissions')), ('audit', ('سجل التدقيق', 'Audit Log'))]
WD = [('الأحد', 'Sunday'), ('الاثنين', 'Monday'), ('الثلاثاء', 'Tuesday'), ('الأربعاء', 'Wednesday'), ('الخميس', 'Thursday'), ('الجمعة', 'Friday'), ('السبت', 'Saturday')]


def admin_settings(state='default'):
    def make():
        days = ''.join('<option%s>%s</option>' % (' selected' if e == 'Thursday' else '', esc(T(a, e))) for a, e in WD)
        notice = '<div class="notice notice-success is-dismissible"><p>%s</p></div>' % esc(T('تم حفظ الإعدادات.', 'Settings saved.')) if state == 'saved' else ''
        switch = '<label class="wfo-feature-status"><input type="checkbox" checked> %s</label>' % esc(T('مفعّل', 'Enabled'))
        rows = [
            (T('ميعاد القفل', 'Cutoff'), ('<select>%s</select> <input type="time" value="17:00"> <p class="description">%s</p>') % (
                days, esc(T('كل أسبوع في الميعاد ده، جدول الأسبوع الجاي بيبقى نهائي. التوقيت بتوقيت الموقع: القاهرة (UTC+3)، من إعدادات WordPress ← عام.',
                            'Every week at this time, next week\'s schedule becomes final. Site time: Cairo (UTC+3), from WordPress Settings → General.')))),
            (T('دلوقتي', 'Now'), '<p class="wfo-wl-now">%s<span>%s</span></p>' % (icon('lock', 14, 2.2), esc(T(
                'القفل الجاي: الخميس 8 أكتوبر 5:00 م (أسبوع 11 – 15 أكتوبر). الأسابيع اللي قبله نهائية.',
                'Next cutoff: Thu 8 Oct, 5:00 PM (the week of 11 – 15 Oct). Weeks before it are final.')))),
            (T('مين يقدر يعدّل أسبوع نهائي', 'Who can edit a final week'), ('<p>%s</p><p class="description">%s <a href="#">%s</a></p>') % (
                esc(T('مدير الموقع ودور EWS Administrator.', 'Site administrators and the EWS Administrator role.')),
                esc(T('صلاحية «تعديل أسبوع مقفول». تقدر تديها لأي دور تاني (زي HR) من', 'The "Edit a locked week" permission. Give it to other roles (such as HR) on')),
                esc(T('الأدوار والصلاحيات', 'Roles & Permissions')))),
            (T('الطلبات', 'Requests'), '<p>%s</p><p class="description">%s</p>' % (
                esc(T('الإجازات وإلغاء الإجازات والتبديلات بتتطلب وتتوافق وتتطبّق عادي، حتى على أيام نهائية.', 'Leave, leave cancellations and swaps are requested, approved and applied as usual, also on final days.')),
                esc(T('كل تغيير بيدخل أسبوع نهائي بيتسجّل في سجل التدقيق.', 'Every change written into a final week is recorded in the Audit Log.')))),
            (T('الموظفين', 'Employees'), '<label><input type="checkbox" checked> %s</label><p class="description">%s</p>' % (
                esc(T('ابعت لكل موظف إشعار وقت القفل: «جدول الأسبوع الجاي بقى نهائي»', 'Send everyone a notification at the cutoff: "Next week\'s schedule is final"')),
                esc(T('جدول الموظف بيوري علامة «نهائي» على الأسبوع في كل الأحوال.', 'Employees always see a "Final" mark on the week in their schedule.')))),
        ]
        form = '<table class="form-table" role="presentation">%s</table>' % ''.join('<tr><th scope="row">%s</th><td>%s</td></tr>' % (esc(k), v) for k, v in rows)
        body = ('<h1>%s</h1>%s<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">%s</div><div class="wfo-feature-desc">%s</div></div>%s</div>'
                '<div class="wfo-wl-admin">%s</div></div><p class="submit"><button class="button button-primary">%s</button></p>') % (
                    esc(T('إعدادات الميزات', 'Feature Configuration')), notice, esc(T('قفل الأسبوع', 'Week lock')),
                    esc(T('من ميعاد ثابت كل أسبوع، جدول الأسبوع الجاي بيبقى نهائي وبيفضل نهائي: المديرين ما يقدروش يغيّروه، والحضور بيفضل بيتصلّح بالتصحيحات زي ما هو. مقفولة افتراضيًا.',
                          'From a fixed time every week, next week\'s schedule is final and stays final: managers can no longer change it; attendance is still fixed through corrections as today. Off by default.')),
                    switch, form, esc(T('حفظ التغييرات', 'Save Changes')))
        return admin_page(T('إعدادات الميزات', 'Feature Configuration'), body, SUBMENU, 'features', css_files=['admin-features.css'], feature_css=CSS)
    return make


def admin_audit():
    def make():
        rows = [
            ('2026-10-12 10:14', T('هبة فاروق', 'Heba Farouk'), 'schedule_locked_edit', T('أحمد سمير · الثلاثاء 13 أكتوبر: مكتب ← من البيت (أسبوع نهائي)', 'Ahmed Samir · Tue 13 Oct: Office → WFH (final week)')),
            ('2026-10-09 15:02', T('النظام', 'System'), 'schedule_locked_by_leave', T('منى عادل · الأربعاء 14 أكتوبر ← إجازة (إجازة #812 اتوافق عليها)', 'Mona Adel · Wed 14 Oct → Leave (leave #812 approved)')),
            ('2026-10-08 17:31', T('كريم فتحي', 'Karim Fathy'), 'schedule_locked_refused', T('3 أيام ما اتحفظتش: أسبوع 11 – 15 أكتوبر نهائي', '3 days not saved: the week of 11 – 15 Oct is final')),
            ('2026-10-01 09:40', T('مدير النظام', 'Site admin'), 'week_lock_update', T('اتفعّل · الخميس 17:00 · إشعار الموظفين: أيوه', 'Enabled · Thursday 17:00 · notify employees: yes')),
        ]
        trs = ''.join('<tr><td dir="ltr">%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>' % (d, esc(u), a, esc(m)) for d, u, a, m in rows)
        body = ('<h1>%s</h1><p>%s</p><table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>') % (
            esc(T('سجل التدقيق', 'Audit Log')), esc(T('الفلتر: قفل الأسبوع', 'Filter: Week lock')),
            esc(T('الوقت', 'Time')), esc(T('مين', 'Who')), esc(T('العملية', 'Action')), esc(T('التفاصيل', 'Details')), trs)
        return admin_page(T('سجل التدقيق', 'Audit Log'), body, SUBMENU, 'audit', feature_css=CSS)
    return make


def build():
    for slug, make in [('soon', attendance('soon')), ('locked', attendance('locked')), ('refused', attendance('refused')),
                       ('override', attendance('override')), ('emp-week', emp_week()), ('emp-push', emp_push()),
                       ('settings', admin_settings()), ('audit', admin_audit())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'Week lock — mockups (Step 1)', (
        '<p>From a weekly cutoff (here <b>Thursday 5:00 PM</b>, site time) the coming week\'s schedule is <b>final</b> and stays final. '
        'Managers can no longer change it; people with the new permission <b>"Edit a locked week"</b> (site administrators and EWS Administrator by default) can, and every change is audited. '
        '<b>Leave, leave cancellations and swaps are still requested, approved and applied automatically</b>, also on final days. Attendance is not locked: corrections work as today. '
        'Off by default; when off nothing changes.</p><p>Release 2 (right after): a manager\'s change on a final day becomes a request to HR with a reason. In release 1 it is refused with a message.</p>'), [
        ('The manager — app → Attendance (the real page, the week of 11 – 15 Oct)', '', [
            ('soon', 'Thursday noon: the week becomes final today at 5:00 PM', D),
            ('locked', 'After the cutoff: the week is final, the days are read-only', D),
            ('locked', 'The same on a phone', P),
            ('refused', 'Saved after the cutoff passed while editing: the changes to the final week are refused', D)]),
        ('HR / administrator with "Edit a locked week"', '', [('override', 'The week is final, but they can still edit it (audited)', D)]),
        ('The employee', '', [('emp-week', 'Schedule: a "Final" mark on the week; leave and swaps still work', P),
                              ('emp-push', 'The notification at the cutoff', P)]),
        ('wp-admin', '', [('settings', 'Feature Configuration → Week lock', D), ('audit', 'Audit Log: what the lock records', D)]),
    ])
