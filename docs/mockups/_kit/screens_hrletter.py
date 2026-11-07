"""HR letters (owner request and decisions 2026-11-07, design docs/tasks/hr-letter.md; Step 1 mockups).

An employee asks HR for a letter from My requests (type from a list or "Other", addressed to, Arabic or English, an optional note);
it goes through the HR Letter approval workflow. Each type has its delivery: a PDF made by the system from the type's template, a
file HR uploads, or the request only (HR marks it ready to collect). Off by default.
"""
from build import T, esc, icon, app_page, admin_page, write, gallery
import build as _build

_build.ICONS.setdefault('info', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>')
_build.ICONS.setdefault('file', '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>')

F = 'hr-letter'
CSS = 'hr-letter.css'
WHO = ('أحمد سمير', 'Ahmed Samir')
TYPES = [(('شهادة عمل', 'Employment certificate'), ('PDF تلقائي', 'Automatic PDF')),
         (('شهادة مرتب', 'Salary certificate'), ('PDF تلقائي', 'Automatic PDF')),
         (('شهادة خبرة', 'Experience letter'), ('HR ترفع الملف', 'HR uploads the file')),
         (('خطاب سفارة', 'Embassy letter'), ('HR ترفع الملف', 'HR uploads the file')),
         (('خطاب بنك', 'Bank letter'), ('طلب فقط', 'Request only'))]


def tile(kind, ic, label, line, off=False):
    return ('<button type="button" class="wfo-new-type tone-%s"%s><span class="wfo-new-ic" aria-hidden="true">%s</span><b>%s</b><small>%s</small></button>') % (
        kind, ' disabled' if off else '', icon(ic, 18, 2), esc(label), esc(line))


def new_sheet():
    def make():
        body = ('<dialog class="wfo-sheet wfo-new" open><div class="wfo-sheet-body"><div class="wfo-sheet-grip"></div><div class="wfo-sheet-head"><h2>%s</h2></div>'
                '<p class="wfo-new-group">%s</p><div class="wfo-new-types">%s%s</div>'
                '<p class="wfo-new-group">%s</p><div class="wfo-new-types">%s%s</div></div></dialog>') % (
                    esc(T('محتاج إيه؟', 'What do you need?')), esc(T('إجازات وساعات', 'Time off and hours')),
                    tile('leave', 'leave', T('إجازة', 'Leave'), T('١٤ يوم سنوي متبقي', '14 Annual left')), tile('overtime', 'overtime', T('وقت إضافي', 'Overtime'), T('قبل أو بعد الشغل', 'Before or after work')),
                    esc(T('فلوس ومستندات', 'Money and documents')),
                    tile('advance', 'cash', T('سلفة', 'Salary advance'), T('لحد ٥٠٪ من المرتب', 'Up to 50% of your salary')),
                    tile('letter', 'file', T('خطاب HR', 'HR letter'), T('شهادة عمل، مرتب، خبرة، سفارة، بنك', 'Employment, salary, experience, embassy, bank')))
        return app_page(T('طلباتي', 'My requests'), '<div class="wfo-apv is-mine"></div>' + body, active='dashboard', css_files=['app-approvals.css', 'app-requests.css'], feature_css=CSS, who=WHO)
    return make


def letter_sheet(other=False):
    def make():
        opts = ''.join('<option%s>%s</option>' % (' selected' if (i == 1 and not other) else '', esc(T(*t))) for i, (t, _) in enumerate(TYPES))
        opts += '<option%s>%s</option>' % (' selected' if other else '', esc(T('أخرى…', 'Other…')))
        hint = (T('HR هتكتب الخطاب وترفعه هنا، وهيوصلك إشعار لما يجهز.', 'HR will write the letter and attach it here; you will be told when it is ready.') if other
                else T('بعد الموافقة الخطاب بيتعمل PDF تلقائي وتحمّله من طلباتي. فيه مرتبك.', 'Once approved, the letter is made as a PDF you download from My requests. It shows your salary.'))
        other_f = ('<div class="wfo-rq-field"><label>%s</label><input type="text" value="%s"></div>' % (esc(T('محتاج إيه بالظبط؟', 'What exactly do you need?')),
                   esc(T('خطاب للجامعة بمواعيد الشغل', 'A letter for the university with my working hours')))) if other else ''
        body = ('<dialog class="wfo-sheet" open><form class="wfo-sheet-body" onsubmit="return false"><div class="wfo-sheet-grip"></div>'
                '<div class="wfo-sheet-head"><button type="button" class="wfo-sheet-x">%s</button><h2>%s</h2></div>'
                '<div class="wfo-rq-field"><label>%s</label><select>%s</select></div>%s'
                '<div class="wfo-rq-field"><label>%s</label><input type="text" value="%s"></div>'
                '<div class="wfo-rq-field"><label>%s</label><div class="wfo-hl-seg"><label><input type="radio" name="lang" checked> %s</label><label><input type="radio" name="lang"> English</label></div></div>'
                '<div class="wfo-rq-field"><label>%s <span class="wfo-optional">%s</span></label><textarea rows="2"></textarea></div>'
                '<p class="wfo-sheet-note">%s %s</p>'
                '<button class="wfo-sheet-submit" type="submit">%s%s</button></form></dialog>') % (
                    icon('chevron', 20, 2), esc(T('خطاب HR', 'HR letter')),
                    esc(T('نوع الخطاب', 'Letter type')), opts, other_f,
                    esc(T('موجّه إلى', 'Addressed to')), esc(T('بنك مصر', 'Banque Misr')),
                    esc(T('لغة الخطاب', 'Language')), esc(T('عربي', 'Arabic')),
                    esc(T('ملاحظة لـ HR', 'Note for HR')), esc(T('(اختياري)', '(optional)')),
                    icon('info', 14, 2.2), esc(hint), icon('arrow', 18, 2.2), esc(T('ابعت الطلب', 'Send request')))
        return app_page(T('طلباتي', 'My requests'), body, active='dashboard', css_files=['app-approvals.css', 'app-requests.css'], feature_css=CSS, who=WHO)
    return make


def mine():
    def make():
        def item(state, label, title, sub, extra=''):
            return ('<article class="wfo-apv-item tone-letter"><div class="wfo-apv-who"><div class="wfo-apv-t"><b>%s</b><small>%s</small></div><span class="wfo-apv-state is-%s">%s</span></div>%s</article>') % (
                esc(title), esc(sub), state, esc(label), extra)
        dl = '<a class="wfo-apv-btn is-ok is-sm wfo-hl-dl" href="#">%s%s</a>' % (icon('download', 16, 2), esc(T('تحميل الخطاب (PDF)', 'Download the letter (PDF)')))
        wd = '<details class="wfo-apv-withdraw"><summary class="wfo-apv-btn is-bad is-sm">%s</summary></details>' % esc(T('سحب', 'Withdraw'))
        items = [
            item('approved', T('جاهز', 'Ready'), T('شهادة مرتب · عربي', 'Salary certificate · Arabic'), T('إلى بنك مصر · HR-2026-000123 · صدر السبت ٧ نوفمبر', 'To Banque Misr · HR-2026-000123 · issued Sat 7 Nov'), dl),
            item('waiting', T('مستني · المستوى ١ من ١', 'Waiting · level 1 of 1'), T('شهادة عمل · English', 'Employment certificate · English'), T('إلى من يهمه الأمر · هبة فاروق (الموارد البشرية) بتقرّر', 'To whom it may concern · Heba Farouk (HR) decides'), wd),
            item('approved', T('اتوافق · HR بتجهّزه', 'Approved · HR is preparing it'), T('خطاب سفارة · English', 'Embassy letter · English'), T('إلى سفارة ألمانيا · هيوصلك إشعار لما HR ترفعه', 'To the German Embassy · you will be told when HR attaches it')),
            item('approved', T('جاهز للاستلام', 'Ready to collect'), T('خطاب بنك · عربي', 'Bank letter · Arabic'), T('استلمه من HR · مكتب ٢٠٤', 'Collect it from HR · office 204')),
            item('rejected', T('اترفض', 'Rejected'), T('شهادة خبرة · عربي', 'Experience letter · Arabic'), T('السبب: شهادة الخبرة بتطلع عند ترك العمل', 'Reason: experience letters are issued on leaving')),
        ]
        body = '<div class="wfo-apv is-mine"><nav class="wfo-apv-filters"><a href="#" aria-current="true">%s</a><a href="#">%s</a></nav><div class="wfo-apv-list">%s</div></div>' % (
            esc(T('الكل', 'All')), esc(T('مستني · ١', 'Waiting · 1')), ''.join(items))
        return app_page(T('طلباتي', 'My requests'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=WHO)
    return make


def approve():
    def make():
        facts = ''.join('<dt>%s</dt><dd>%s</dd>' % (esc(k), esc(v)) for k, v in [
            (T('النوع', 'Type'), T('شهادة مرتب', 'Salary certificate')), (T('اللغة', 'Language'), T('عربي', 'Arabic')),
            (T('موجّه إلى', 'Addressed to'), T('بنك مصر', 'Banque Misr')), (T('التسليم', 'Delivery'), T('PDF تلقائي بعد الموافقة', 'Automatic PDF once approved')),
            (T('بيانات الموظف', 'Employee data'), T('الاسم بالعربي ✓ · النوع ✓ · تاريخ التعيين ١ مارس ٢٠٢١', 'Arabic name ✓ · gender ✓ · hired 1 Mar 2021')),
            (T('ملاحظة', 'Note'), T('محتاجها للقرض قبل الخميس', 'Needed for the loan before Thursday'))])
        card = ('<article class="wfo-apv-item tone-letter"><form onsubmit="return false"><div class="wfo-apv-who"><span class="wfo-apv-av">%s</span><div class="wfo-apv-t"><b>%s</b><small>%s</small></div></div>'
                '<p class="wfo-apv-what">%s</p><div class="wfo-apv-meta"><span class="wfo-apv-pill">%s</span><span class="wfo-apv-pill">%s</span><span class="wfo-apv-pill is-warn">%s</span></div>'
                '<details class="wfo-apv-more" open><summary>%s</summary><dl class="wfo-apv-facts">%s</dl><label class="wfo-apv-note"><span>%s</span><textarea rows="2"></textarea></label></details>'
                '<div class="wfo-apv-acts"><button type="button" class="wfo-apv-btn is-ok">%s</button><button type="button" class="wfo-apv-btn is-bad">%s</button></div></form></article>') % (
                    esc(T('أس', 'AS')), esc(T(*WHO)), esc(T('خطاب HR · محاسب أول، الحسابات', 'HR letter · Senior Accountant, Accounts')),
                    esc(T('شهادة مرتب · عربي · إلى بنك مصر', 'Salary certificate · Arabic · to Banque Misr')),
                    esc(T('المستوى ١ من ١', 'Level 1 of 1')), esc(T('مستني ساعتين عمل', 'Waiting 2 working h')), esc(T('فيه المرتب', 'Shows the salary')),
                    esc(T('التفاصيل', 'Details')), facts, esc(T('ملاحظة (لازم للرفض)', 'Note (needed to reject)')),
                    esc(T('موافقة وإصدار الخطاب', 'Approve and issue the letter')), esc(T('رفض', 'Reject')))
        body = '<div class="wfo-apv"><div class="wfo-apv-list">%s</div></div>' % card
        return app_page(T('موافقاتي', 'My approvals'), body, active='dashboard', css_files=['app-approvals.css'], feature_css=CSS, who=('هبة فاروق', 'Heba Farouk'))
    return make


SUB = [('dash', ('لوحة التحكم', 'Dashboard')), ('emp', ('الموظفين', 'Employees')), ('req', ('مركز الطلبات', 'Requests Hub')),
       ('letters', ('خطابات HR', 'HR letters')), ('appr', ('مسارات الموافقات', 'Approval Workflows')), ('feat', ('المميزات', 'Features'))]


def tabs(cur):
    return '<nav class="nav-tab-wrapper">%s</nav>' % ''.join('<a href="#" class="nav-tab%s">%s</a>' % (' nav-tab-active' if k == cur else '', esc(T(*l))) for k, l in [
        ('list', ('الخطابات', 'Letters')), ('types', ('الأنواع والقوالب', 'Types & templates')), ('head', ('الترويسة', 'Letterhead'))])


def head():
    return '<h1 class="wp-heading-inline">%s</h1> <a href="#" class="page-title-action">%s</a><hr class="wp-header-end">' % (
        esc(T('خطابات HR', 'HR letters')), esc(T('خطاب لموظف', 'Letter for an employee')))


def admin_list():
    def make():
        rows = [('HR-2026-000124', T('أحمد سمير', 'Ahmed Samir'), T('شهادة عمل · English', 'Employment certificate · English'), T('مستني موافقة', 'Waiting for a decision'), T('—', '—')),
                ('HR-2026-000121', T('منى عادل', 'Mona Adel'), T('خطاب سفارة · English', 'Embassy letter · English'), T('اتوافق · مستني الملف', 'Approved · waiting for the file'), T('رفع ملف', 'Upload file')),
                ('HR-2026-000123', T('أحمد سمير', 'Ahmed Samir'), T('شهادة مرتب · عربي', 'Salary certificate · Arabic'), T('صدر · PDF تلقائي', 'Issued · automatic PDF'), T('تحميل · إعادة إصدار', 'Download · Regenerate')),
                ('HR-2026-000119', T('سلمى عاطف (سابقة)', 'Salma Atef (former)'), T('شهادة خبرة · عربي', 'Experience letter · Arabic'), T('صدر · مرفوع', 'Issued · uploaded'), T('تحميل · استبدال', 'Download · Replace')),
                ('HR-2026-000118', T('كريم ناجي', 'Karim Nagi'), T('خطاب بنك · عربي', 'Bank letter · Arabic'), T('جاهز للاستلام', 'Ready to collect'), T('—', '—'))]
        tr = ''.join('<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' % tuple(esc(x) for x in r) for r in rows)
        status = [(T('الكل', 'All'), 5), (T('مستني قرار', 'Waiting for a decision'), 1), (T('مستني ملف', 'Waiting for a file'), 1), (T('صدرت', 'Issued'), 2)]
        subsub = '<ul class="subsubsub">%s</ul>' % ' |'.join('<li><a href="#"%s>%s <span class="count">(%d)</span></a></li>' % (' class="current"' if i == 0 else '', esc(l), n) for i, (l, n) in enumerate(status))
        upload = ('<div class="ews-hl-box"><h2>%s</h2><p>%s</p><p><input type="file" accept="application/pdf"> <button class="button button-primary">%s</button></p><p class="description">%s</p></div>') % (
            esc(T('رفع خطاب HR-2026-000121', 'Upload letter HR-2026-000121')), esc(T('منى عادل · خطاب سفارة · English · إلى سفارة ألمانيا', 'Mona Adel · Embassy letter · English · to the German Embassy')),
            esc(T('رفع وإبلاغ الموظفة', 'Upload and tell her')), esc(T('PDF بس، لحد ١٠ ميجا. الملف خاص: الموظفة وHR بس يشوفوه.', 'PDF only, up to 10 MB. Private: only she and HR can open it.')))
        table = ('<table class="wp-list-table widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>') % (
            esc(T('الرقم', 'Ref')), esc(T('الموظف', 'Employee')), esc(T('الخطاب', 'Letter')), esc(T('الحالة', 'Status')), esc(T('إجراء', 'Action')), tr)
        return admin_page(T('خطابات HR', 'HR letters'), head() + tabs('list') + subsub + '<br class="clear">' + table + upload, SUB, 'letters', feature_css=CSS)
    return make


def admin_types():
    def make():
        tr = ''.join('<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>' % (esc(T(*t)), esc(T(*m)), esc(T('عربي · English', 'Arabic · English') if i < 2 else T('—', '—')), esc(T('فعّال', 'Active'))) for i, (t, m) in enumerate(TYPES))
        chips = ''.join('<button type="button" class="button button-small">{%s}</button> ' % c for c in ['employee_name', 'job_title', 'department', 'hire_date', 'addressee', 'date', 'date_hijri', 'ref_no', 'salary_total', 'salary_in_words', 'currency', 'title_mr_ms'])
        tpl = T('تشهد شركة {company_name} بأن {title_mr_ms} / {employee_name} يعمل لديها بوظيفة {job_title} بقسم {department}، اعتبارًا من {hire_date} وحتى تاريخه، ويتقاضى مرتبًا شهريًا إجماليًا قدره {salary_total} {currency} ({salary_in_words}).\n\nوقد أعطيت له هذه الشهادة بناءً على طلبه لتقديمها إلى {addressee}، دون أدنى مسؤولية على الشركة.',
                'This is to certify that {title_mr_ms} {employee_name} has been employed by {company_name} as {job_title} in the {department} department since {hire_date}, and receives a gross monthly salary of {currency} {salary_total}.\n\nThis certificate is issued at their request to be presented to {addressee}, without any liability on the company.')
        edit = ('<div class="ews-hl-box"><h2>%s</h2><table class="form-table"><tr><th>%s</th><td><input type="text" value="%s" class="regular-text"> <input type="text" value="Salary certificate" class="regular-text"></td></tr>'
                '<tr><th>%s</th><td><select><option>%s</option><option selected>%s</option><option>%s</option></select></td></tr>'
                '<tr><th>%s</th><td><p>%s</p><textarea rows="7" class="large-text">%s</textarea><p class="description">%s</p>'
                '<p class="ews-hl-err">%s</p></td></tr></table><p><button class="button">%s</button> <button class="button button-primary">%s</button></p></div>') % (
                    esc(T('تعديل: شهادة مرتب', 'Edit: Salary certificate')), esc(T('الاسم (عربي · English)', 'Name (Arabic · English)')), esc('شهادة مرتب'),
                    esc(T('التسليم', 'Delivery')), esc(T('طلب فقط', 'Request only')), esc(T('PDF تلقائي', 'Automatic PDF')), esc(T('HR ترفع الملف', 'HR uploads the file')),
                    esc(T('القالب العربي', 'English template')), chips, esc(tpl),
                    esc(T('القالب ده فيه المرتب، فحفظه محتاج كمان صلاحية "إدارة المرتبات". سطر فاضي = فقرة جديدة.', 'This template shows the salary, so saving it also needs "Manage payroll". A blank line starts a paragraph.')),
                    esc(T('مثال على رفض الحفظ: {salary_net} مش من القايمة.', 'Example of a refused save: {salary_net} is not one of the placeholders.')),
                    esc(T('معاينة لموظف…', 'Preview for an employee…')), esc(T('حفظ', 'Save')))
        table = ('<table class="wp-list-table widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>') % (
            esc(T('النوع', 'Type')), esc(T('التسليم', 'Delivery')), esc(T('قوالب', 'Templates')), esc(T('الحالة', 'Status')), tr)
        note = '<p class="description">%s</p>' % esc(T('"أخرى" دايمًا بتروح لـ HR ترفع الخطاب. الأنواع ماتتمسحش، بتتوقف بس.', '"Other" always goes to HR to upload. Types are never deleted, only deactivated.'))
        return admin_page(T('خطابات HR', 'HR letters'), head() + tabs('types') + table + note + edit, SUB, 'letters', feature_css=CSS)
    return make


def admin_head():
    def make():
        img = lambda label: '<div class="ews-hl-img"><span>%s</span><button class="button button-small">%s</button></div>' % (esc(label), esc(T('تغيير', 'Change')))
        body = ('<div class="ews-hl-box"><table class="form-table">'
                '<tr><th>%s</th><td><input class="regular-text" value="%s"> <input class="regular-text" value="Nile Contracting"></td></tr>'
                '<tr><th>%s</th><td><input class="large-text" value="%s"></td></tr>'
                '<tr><th>%s</th><td><input class="regular-text" value="%s"> <input class="regular-text" value="%s"></td></tr>'
                '<tr><th>%s</th><td class="ews-hl-imgs">%s%s%s<p class="description">%s</p></td></tr>'
                '<tr><th>%s</th><td><p>%s</p></td></tr>'
                '</table><p><button class="button button-primary">%s</button></p></div>') % (
                    esc(T('اسم الشركة', 'Company name')), esc('شركة النيل للمقاولات'),
                    esc(T('العنوان', 'Address')), esc(T('القاهرة، مصر الجديدة، شارع الحجاز ١٢', '12 Hegaz St., Heliopolis, Cairo')),
                    esc(T('اللي بيمضي', 'Signatory')), esc(T('هبة فاروق', 'Heba Farouk')), esc(T('مدير الموارد البشرية', 'HR Manager')),
                    esc(T('الصور', 'Images')), img(T('اللوجو', 'Logo')), img(T('الإمضاء', 'Signature')), img(T('الختم', 'Stamp')),
                    esc(T('JPEG أو PNG لحد ١ ميجا. الإمضاء والختم بيتحفظوا في مكان خاص، مش في مكتبة الوسائط، ومحدش يقدر يفتحهم من لينك.', 'JPEG or PNG up to 1 MB. The signature and stamp are kept private, never in the Media Library, and cannot be opened from a link.')),
                    esc(T('الخط', 'Font')), esc(T('Amiri (عربي رسمي)، مدمج في البلجن', 'Amiri (formal Arabic), bundled with the plugin')),
                    esc(T('حفظ', 'Save')))
        return admin_page(T('خطابات HR', 'HR letters'), head() + tabs('head') + body, SUB, 'letters', feature_css=CSS)
    return make


def features():
    def make():
        body = ('<h1>%s</h1><div class="ews-hl-box"><h2>%s <span class="ews-hl-off">%s</span></h2><p>%s</p>'
                '<p><label><input type="checkbox"> %s</label></p>'
                '<table class="form-table"><tr><th>%s</th><td><label><input type="checkbox" checked> عربي</label> <label><input type="checkbox" checked> English</label></td></tr>'
                '<tr><th>%s</th><td><input class="regular-text" value="%s"> <input class="regular-text" value="To whom it may concern"></td></tr>'
                '<tr><th>%s</th><td><label><input type="checkbox" checked> %s</label></td></tr>'
                '<tr><th>%s</th><td><label><input type="checkbox" checked> %s</label></td></tr></table>'
                '<div class="notice notice-warning inline"><p>%s</p></div><div class="notice notice-warning inline"><p>%s</p></div>'
                '<p><a href="#">%s</a> · <a href="#">%s</a></p></div>') % (
                    esc(T('المميزات', 'Features')), esc(T('خطابات HR', 'HR letters')), esc(T('مقفول', 'Off')),
                    esc(T('الموظف يطلب خطاب من HR من "طلباتي"، والطلب يمشي في مسار موافقات "خطاب HR".', 'Employees ask HR for a letter from My requests; it goes through the "HR Letter" approval workflow.')),
                    esc(T('تشغيل خطابات HR', 'Turn HR letters on')), esc(T('اللغات', 'Languages')),
                    esc(T('موجّه إلى (افتراضي)', 'Addressed to (default)')), esc('إلى من يهمه الأمر'),
                    esc(T('"أخرى"', '"Other"')), esc(T('الموظف يقدر يكتب طلب مش في القايمة', 'Employees may ask for a letter not in the list')),
                    esc(T('طلب مفتوح واحد', 'One open request')), esc(T('طلب واحد مستني لكل نوع ولغة', 'One waiting request per type and language')),
                    esc(T('الموارد البشرية مش متحدّدة في مسارات الموافقات: الطلبات هتروح للمسؤولين.', 'HR is not set in Approval Workflows: requests will go to the administrators.')),
                    esc(T('"موافقاتي وطلباتي" مقفولة: الموظفين مش هيلاقوا مكان يطلبوا منه.', 'My approvals and My requests is off: employees will have nowhere to ask.')),
                    esc(T('الأنواع والقوالب', 'Types & templates')), esc(T('مسار "خطاب HR"', 'The "HR Letter" workflow')))
        return admin_page(T('المميزات', 'Features'), body, SUB, 'feat', feature_css=CSS)
    return make


def letter_page():
    def make():
        body = ('<div class="ews-hl-sample"><p>%s</p><img src="letter-ar.png" alt=""><img src="letter-en.png" alt=""><p><a href="letter-ar.pdf">%s</a></p></div>') % (
            esc(T('الخطاب زي ما المولّد بيطلعه (بخط Amiri). اللوجو والإمضاء والختم بيتضافوا في التنفيذ.', 'The letter as the generator makes it (Amiri). The logo, signature and stamp are added in the implementation.')),
            esc(T('الملف PDF', 'The PDF file')))
        return admin_page(T('شكل الخطاب', 'The letter'), body, SUB, 'letters', feature_css=CSS)
    return make


def build():
    for slug, make in [('new', new_sheet()), ('sheet', letter_sheet()), ('sheet-other', letter_sheet(True)), ('mine', mine()), ('approve', approve()),
                       ('admin-list', admin_list()), ('admin-types', admin_types()), ('admin-letterhead', admin_head()), ('features', features()), ('letter', letter_page())]:
        write(F, slug, make)
    P, D = 'phone', 'desk'
    gallery(F, 'HR letters — mockups', (
        '<p><b>Employees ask HR for a letter</b> from My requests (Money and documents → HR letter): the type from a list or "Other", who it '
        'is addressed to, Arabic or English, an optional note. It goes through the <b>HR Letter</b> approval workflow (HR by default; an HR '
        'employee&#39;s own letter goes to the administrators).</p><p>Each type has its delivery: an <b>automatic PDF</b> from the type&#39;s template '
        '(Amiri font, Arabic-Indic digits, salary in words, Hijri date), a <b>file HR uploads</b>, or <b>request only</b> (HR marks it ready to collect). '
        '"Other" always goes to HR to upload. A template with the salary needs Manage Payroll to save; files are private. Off by default.</p>'), [
        ('The employee', '', [('new', 'New request: the HR letter tile', P), ('sheet', 'The letter form (automatic PDF, with the salary)', P),
                              ('sheet-other', '"Other": HR uploads it', P), ('mine', 'My requests: ready, waiting, HR preparing, ready to collect, rejected', P)]),
        ('HR deciding', '', [('approve', 'My approvals: the HR card', P)]),
        ('HR admin', '', [('admin-list', 'Letters: statuses, upload a file', D), ('admin-types', 'Types & templates: delivery per type, placeholders, salary rule', D),
                          ('admin-letterhead', 'Letterhead: logo, signature, stamp (private)', D), ('features', 'Features: the switch (off) and its warnings', D)]),
        ('The letter', '', [('letter', 'Arabic and English, from the built-in generator', D)]),
    ])
