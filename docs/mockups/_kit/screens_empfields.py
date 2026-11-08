"""Employee details for letters (HR letters HL2, owner decision 2026-11-07 Q11/Q12; docs/tasks/hr-letter.md §16).

Three optional fields on an employee: the name in Arabic, gender and hiring date. HR letters use them when they are filled
(an Arabic letter takes the Arabic name, "Mr." / "Ms." and the hiring date); empty, a letter falls back to the employee's
name, wording that fits anyone, and the day the employee was added. Edited on the employee's profile in wp-admin; also in the
Add Employee form and the Excel import. Nothing changes for anyone until they are filled.
"""
from build import T, esc, icon, app_page, admin_page, write, gallery

F = 'employee-fields'
CSS = 'employee-fields.css'
SUB = [('dash', ('لوحة التحكم', 'Dashboard')), ('emp', ('الموظفين', 'Employees')), ('req', ('مركز الطلبات', 'Requests Hub')),
       ('appr', ('مسارات الموافقات', 'Approval Workflows')), ('feat', ('المميزات', 'Features'))]


def field(k, v, muted=False):
    return '<div class="ews-profile-field"><div class="k">%s</div><div class="v%s">%s</div></div>' % (esc(k), ' ews-ef-empty' if muted else '', esc(v))


def head():
    return ('<div class="ews-profile-head"><div class="ews-profile-avatar">أس</div><div><h1 class="wp-heading-inline">%s</h1><p class="ews-profile-sub">%s</p></div></div>') % (
        esc(T('أحمد سمير', 'Ahmed Samir')), esc(T('محاسب أول · الحسابات', 'Senior Accountant · Accounts')))


def info(edit=False, filled=True):
    """The real Employee Information panel: the Arabic name under the name, the hiring date as "Employee Since" (the day the employee was
    added while it is not set), gender the only new row. No separate block, nothing shown twice."""
    name = '<div class="ews-profile-field"><div class="k">%s</div><div class="v">Ahmed Samir%s</div></div>' % (
        esc(T('اسم الموظف', 'Employee Name')), ('<br><span class="ews-ef-sub" dir="rtl" lang="ar">أحمد سمير عبد الله</span>' if filled else ''))
    since = '<div class="ews-profile-field"><div class="k">%s</div><div class="v">%s%s</div></div>' % (
        esc(T('موظف من', 'Employee Since')), esc(T('١ مارس ٢٠٢١', '1 Mar 2021') if filled else T('١٢ يناير ٢٠٢٣', '12 Jan 2023')),
        '' if filled else '<br><span class="ews-ef-sub">%s</span>' % esc(T('تاريخ إضافته للنظام: تاريخ التعيين مش متسجل', 'Added to the system: hiring date not set')))
    gender = field(T('النوع', 'Gender'), T('ذكر', 'Male') if filled else T('مش متسجل', 'Not set'), not filled)
    base = (name + field(T('اسم الدومين', 'Domain Name'), 'asamir') + field(T('البريد', 'Email'), 'asamir@nile.example')
            + field(T('مستخدم ووردبريس', 'WordPress User'), 'asamir') + field(T('الحالة', 'Status'), T('نشط', 'Active')) + since + gender)
    if not edit:
        tail = '<p class="ews-ef-actions"><a href="#" class="button button-small">%s</a> <span class="description">%s</span></p>' % (
            esc(T('تعديل الاسم بالعربي والنوع وتاريخ التعيين', 'Edit name in Arabic, gender, hiring date')),
            esc(T('اختيارية، وخطابات HR بتستخدمها لما تكون متسجلة.', 'Optional; HR letters use them when they are filled.')))
    else:
        tail = ('<form class="ews-ef-block ews-ef-form">'
                '<label>%s<input type="text" dir="rtl" lang="ar" value="أحمد سمير عبد الله" maxlength="190"></label>'
                '<label>%s<select><option>%s</option><option selected>%s</option><option>%s</option></select></label>'
                '<label>%s<input type="date" value="2021-03-01"></label>'
                '<p class="description">%s</p><p><button class="button button-primary">%s</button> <a href="#" class="button">%s</a></p></form>') % (
                    esc(T('الاسم بالعربي', 'Name in Arabic')),
                    esc(T('النوع', 'Gender')), esc(T('— مش متسجل —', '— Not set —')), esc(T('ذكر', 'Male')), esc(T('أنثى', 'Female')),
                    esc(T('تاريخ التعيين', 'Hiring date')),
                    esc(T('لو فاضية: الخطاب بيستخدم اسم الموظف، وكلام يناسب الكل ("السيد/السيدة")، وتاريخ إضافته للنظام. التعديل بيتسجل في سجل التدقيق.',
                          'Left empty, a letter uses the employee name, wording that fits anyone ("Mr./Ms."), and the day the employee was added. Changes are kept in the Audit Log.')),
                    esc(T('حفظ', 'Save')), esc(T('إلغاء', 'Cancel')))
    return ('<div class="ews-profile-layout"><div><section class="ews-profile-panel"><h2>%s</h2><div class="ews-profile-body"><div class="ews-profile-fields">%s</div>%s</div></section></div></div>') % (
        esc(T('بيانات الموظف', 'Employee Information')), base, tail)


def profile(edit=False, filled=True):
    def make():
        return admin_page(T('ملف الموظف', 'Employee profile'), head() + info(edit, filled), SUB, 'emp', css_files=('admin-employee-profile.css',), feature_css=CSS)
    return make


def add_form():
    def make():
        body = ('<h1>%s</h1><form class="ews-ef-add"><h2>%s</h2><p><input placeholder="%s"></p><p><input placeholder="%s"></p><p><input placeholder="%s"></p>'
                '<details open><summary>%s</summary><p><input dir="rtl" lang="ar" placeholder="%s"></p>'
                '<p><select><option>%s</option><option>%s</option><option>%s</option></select> <label>%s <input type="date"></label></p></details>'
                '<p><button class="button button-primary">%s</button></p></form>') % (
                    esc(T('الموظفين', 'Employees')), esc(T('إضافة موظف', 'Add Employee')), esc(T('اسم الموظف', 'Employee Name')),
                    esc(T('اسم الدومين', 'Domain Name')), esc(T('البريد', 'Email')),
                    esc(T('بيانات الخطابات (اختياري)', 'Details for letters (optional)')), esc(T('الاسم بالعربي', 'Name in Arabic')),
                    esc(T('النوع: مش متسجل', 'Gender: not set')), esc(T('ذكر', 'Male')), esc(T('أنثى', 'Female')), esc(T('تاريخ التعيين', 'Hiring date')),
                    esc(T('إضافة', 'Add')))
        return admin_page(T('الموظفين', 'Employees'), body, SUB, 'emp', feature_css=CSS)
    return make


def my_profile(filled=True):
    """The real My Information card (templates/app/my-profile.php): the Arabic name under the name, "Employee since" (the hiring date, or
    the day the employee was added), and gender. Read only; nothing shown twice."""
    def make():
        name = 'Ahmed Samir' + ('<br><span class="ews-ef-sub" dir="rtl" lang="ar">أحمد سمير عبد الله</span>' if filled else '')
        rows = [(T('الاسم', 'Name'), name, False), (T('النوع', 'Gender'), esc(T('ذكر', 'Male')) if filled else '', True),
                (T('موظف من', 'Employee since'), esc(T('١ مارس ٢٠٢١', '1 Mar 2021') if filled else T('١٢ يناير ٢٠٢٣', '12 Jan 2023')), False),
                (T('الدومين', 'Domain'), 'asamir', False), (T('البريد', 'Email'), 'asamir@nile.example', False), (T('الفريق', 'Team'), esc(T('فريق الحسابات', 'Accounts Team')), False),
                (T('المدير المباشر', 'Supervisor'), esc(T('كريم ناجي', 'Karim Nagy')), False), (T('الوردية', 'Shift'), esc(T('الافتراضية للشركة', 'Company Default')), False)]
        dl = ''.join('<div><dt>%s</dt><dd%s>%s</dd></div>' % (esc(k), ' class="ews-ef-empty"' if (opt and not v) else '', v or esc(T('مش متسجل', 'Not set'))) for k, v, opt in rows)
        body = ('<div class="ews-my-profile wfo-profile"><div class="wfo-profile-grid"><div class="wfo-profile-col">'
                '<section class="wfo-profile-card"><div class="wfo-profile-card-head"><span class="wfo-profile-card-icon">%s</span><div><h3>%s</h3><p>%s</p></div></div>'
                '<dl class="wfo-profile-fields">%s</dl><p class="ews-ef-note">%s %s</p></section></div></div></div>') % (
                    icon('user', 20), esc(T('بياناتي', 'My Information')), esc(T('بياناتك الشخصية وبيانات الشغل', 'Your personal and work information')), dl,
                    icon('info', 15, 2), esc(T('دي البيانات اللي بتظهر في خطابات HR. فيها حاجة غلط؟ بلّغ الموارد البشرية وهي تصححها.',
                                               'This is what appears on your HR letters. Something wrong? Ask HR to correct it.')))
        return app_page(T('ملفي', 'My Profile'), body, active='more', css_files=('app-profile.css',), feature_css=CSS, who=('أحمد سمير', 'Ahmed Samir'))
    return make


def import_upload():
    def make():
        steps = ''.join('<li>%s</li>' % esc(x) for x in [
            T('نزّل الملف: فيه كل الموظفين ببياناتهم الحالية.', 'Download the file: every employee with their current details.'),
            T('املا الأعمدة name_ar و gender (male أو female) و hire_date (YYYY-MM-DD). أي عمود تاني بيتجاهل.', 'Fill the columns name_ar, gender (male or female) and hire_date (YYYY-MM-DD). Other columns are ignored.'),
            T('ارفعه هنا: هتشوف التغييرات الأول، ومفيش حاجة بتتحفظ قبل ما تدوس "تطبيق".', 'Upload it here: you see the changes first; nothing is saved before you press Apply.')])
        body = ('<h1 class="wp-heading-inline">%s</h1><a href="#" class="page-title-action">%s</a><hr class="wp-header-end">'
                '<div class="ews-ef-add"><h2>%s</h2><ol class="ews-ef-steps">%s</ol>'
                '<p><a href="#" class="button">%s</a></p>'
                '<p><input type="file" accept=".xlsx,.csv"> <button class="button button-primary">%s</button></p>'
                '<p class="description">%s</p></div>') % (
                    esc(T('الموظفين', 'Employees')), esc(T('تحديث البيانات من ملف', 'Update details from a file')),
                    esc(T('تحديث الاسم بالعربي والنوع وتاريخ التعيين من ملف', 'Update name in Arabic, gender and hiring date from a file')), steps,
                    esc(T('تنزيل ملف الموظفين (Excel)', 'Download the employees file (Excel)')), esc(T('رفع ومعاينة', 'Upload and preview')),
                    esc(T('Excel أو CSV. الخانة الفاضية معناها "سيبها زي ما هي".', 'Excel or CSV. An empty cell means "leave it as it is".')))
        return admin_page(T('الموظفين', 'Employees'), body, SUB, 'emp', feature_css=CSS)
    return make


def import_preview():
    def make():
        rows = [('asamir', 'Ahmed Samir', T('الاسم بالعربي', 'Name in Arabic'), '—', 'أحمد سمير عبد الله', ''),
                ('asamir', 'Ahmed Samir', T('النوع', 'Gender'), '—', T('ذكر', 'Male'), ''),
                ('asamir', 'Ahmed Samir', T('تاريخ التعيين', 'Hiring date'), '—', '2021-03-01', ''),
                ('madel', 'Mona Adel', T('تاريخ التعيين', 'Hiring date'), '2022-01-10', '2021-12-10', ''),
                ('knagy', 'Karim Nagy', T('النوع', 'Gender'), '', 'm', T('النوع لازم يكون male أو female', 'Gender must be male or female')),
                ('sfathy', '', '', '', '', T('مفيش موظف بالدومين ده', 'No employee with this domain name')),
                ('ralaa', 'Rana Alaa', T('تاريخ التعيين', 'Hiring date'), '', '2027-01-01', T('التاريخ في المستقبل', 'The date is in the future'))]
        trs = ''.join('<tr%s><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' % (
            ' class="ews-ef-refused"' if err else '', esc(d), esc(n), esc(f), esc(o), esc(nw), esc(err or T('هيتغير', 'Will change'))) for d, n, f, o, nw, err in rows)
        body = ('<h1>%s</h1><div class="notice notice-info inline"><p>%s</p></div>'
                '<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table>'
                '<p><button class="button button-primary">%s</button> <a href="#" class="button">%s</a></p><p class="description">%s</p>') % (
                    esc(T('معاينة التحديث', 'Preview the update')),
                    esc(T('٤ تغييرات لـ ٢ موظف هتتطبق. ٣ صفوف مرفوضة ومش هتتطبق. ٤٢ موظف من غير تغيير.', '4 changes for 2 employees will be applied. 3 rows are refused and will not be applied. 42 employees without a change.')),
                    esc(T('الدومين', 'Domain name')), esc(T('الموظف', 'Employee')), esc(T('الحقل', 'Field')), esc(T('الحالي', 'Now')), esc(T('الجديد', 'New')), esc(T('النتيجة', 'Result')), trs,
                    esc(T('تطبيق ٤ تغييرات', 'Apply 4 changes')), esc(T('إلغاء', 'Cancel')),
                    esc(T('كل تغيير بيتسجل في سجل التدقيق باسمك.', 'Every change is kept in the Audit Log under your name.')))
        return admin_page(T('الموظفين', 'Employees'), body, SUB, 'emp', feature_css=CSS)
    return make


def build():
    for slug, make in [('my-profile', my_profile()), ('my-profile-empty', my_profile(False)), ('profile', profile()), ('profile-empty', profile(filled=False)), ('profile-edit', profile(edit=True)), ('add', add_form()), ('import', import_upload()), ('import-preview', import_preview())]:
        write(F, slug, make)
    D = 'desk'
    gallery(F, 'Employee details for letters — mockups', (
        '<p>Three <b>optional</b> fields on an employee for HR letters (owner decision): <b>the name in Arabic</b>, <b>gender</b> and the '
        '<b>hiring date</b>. An Arabic letter uses the Arabic name; gender picks "Mr." or "Ms." and the matching wording; the hiring date '
        'is the "employed since" date. Left empty, a letter uses the employee name, wording that fits anyone, and the day the employee was '
        'added. Edited on the employee profile in wp-admin, in Add Employee, and for many employees at once from a file (preview, then apply). Changes are audited.</p>'), [
        ('The employee profile', '', [('profile', 'Filled in', D), ('profile-empty', 'Not filled in yet (most employees after the update)', D),
                                      ('profile-edit', 'Editing them', D)]),
        ('Adding employees', '', [('add', 'Add Employee: the optional details', D)]),
        ('Updating existing employees from a file (owner decision 2026-11-08)', '', [('import', 'Download the file, fill it, upload it', D), ('import-preview', 'Preview: what changes, what is refused; then Apply', D)]),
        ('The employee (app, read only; owner decision 2026-11-08)', '', [('my-profile', 'My Profile: the details, filled in', 'phone'),
                                                                         ('my-profile-empty', 'My Profile: not filled in yet', 'phone')]),
    ])
