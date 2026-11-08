"""Employee details for letters (HR letters HL2, owner decision 2026-11-07 Q11/Q12; docs/tasks/hr-letter.md §16).

Three optional fields on an employee: the name in Arabic, gender and hiring date. HR letters use them when they are filled
(an Arabic letter takes the Arabic name, "Mr." / "Ms." and the hiring date); empty, a letter falls back to the employee's
name, wording that fits anyone, and the day the employee was added. Edited on the employee's profile in wp-admin; also in the
Add Employee form and the Excel import. Nothing changes for anyone until they are filled.
"""
from build import T, esc, admin_page, write, gallery

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
    base = (field(T('اسم الموظف', 'Employee Name'), 'Ahmed Samir') + field(T('اسم الدومين', 'Domain Name'), 'asamir')
            + field(T('البريد', 'Email'), 'asamir@nile.example') + field(T('مستخدم ووردبريس', 'WordPress User'), 'asamir')
            + field(T('الحالة', 'Status'), T('نشط', 'Active')) + field(T('موظف من', 'Employee Since'), T('١٢ يناير ٢٠٢٣', '12 Jan 2023')))
    if not edit:
        letters = (field(T('الاسم بالعربي', 'Name in Arabic'), 'أحمد سمير عبد الله' if filled else T('مش متسجل', 'Not set'), not filled)
                   + field(T('النوع', 'Gender'), T('ذكر', 'Male') if filled else T('مش متسجل', 'Not set'), not filled)
                   + field(T('تاريخ التعيين', 'Hiring date'), T('١ مارس ٢٠٢١', '1 Mar 2021') if filled else T('مش متسجل', 'Not set'), not filled))
        block = ('<div class="ews-ef-block"><div class="ews-ef-blockhead"><h3>%s</h3><a href="#" class="button button-small">%s</a></div>'
                 '<div class="ews-profile-fields">%s</div><p class="description">%s</p></div>') % (
                     esc(T('بيانات الخطابات', 'Details for letters')), esc(T('تعديل', 'Edit')), letters,
                     esc(T('اختيارية. خطابات HR بتستخدمها لما تكون متسجلة.', 'Optional. HR letters use them when they are filled.')))
    else:
        block = ('<form class="ews-ef-block ews-ef-form"><div class="ews-ef-blockhead"><h3>%s</h3></div>'
                 '<label>%s<input type="text" dir="rtl" lang="ar" value="أحمد سمير عبد الله" maxlength="190"></label>'
                 '<label>%s<select><option>%s</option><option selected>%s</option><option>%s</option></select></label>'
                 '<label>%s<input type="date" value="2021-03-01"></label>'
                 '<p class="description">%s</p><p><button class="button button-primary">%s</button> <a href="#" class="button">%s</a></p></form>') % (
                     esc(T('بيانات الخطابات', 'Details for letters')),
                     esc(T('الاسم بالعربي', 'Name in Arabic')),
                     esc(T('النوع', 'Gender')), esc(T('— مش متسجل —', '— Not set —')), esc(T('ذكر', 'Male')), esc(T('أنثى', 'Female')),
                     esc(T('تاريخ التعيين', 'Hiring date')),
                     esc(T('لو فاضية: الخطاب بيستخدم اسم الموظف، وكلام يناسب الكل ("السيد/السيدة")، وتاريخ إضافته للنظام. التعديل بيتسجل في سجل التدقيق.',
                           'Left empty, a letter uses the employee name, wording that fits anyone ("Mr./Ms."), and the day the employee was added. Changes are kept in the Audit Log.')),
                     esc(T('حفظ', 'Save')), esc(T('إلغاء', 'Cancel')))
    return ('<div class="ews-profile-layout"><div><section class="ews-profile-panel"><h2>%s</h2><div class="ews-profile-body"><div class="ews-profile-fields">%s</div>%s</div></section></div></div>') % (
        esc(T('بيانات الموظف', 'Employee Information')), base, block)


def profile(edit=False, filled=True):
    def make():
        return admin_page(T('ملف الموظف', 'Employee profile'), head() + info(edit, filled), SUB, 'emp', css_files=('admin-employee-profile.css',), feature_css=CSS)
    return make


def add_form():
    def make():
        body = ('<h1>%s</h1><form class="ews-ef-add"><h2>%s</h2><p><input placeholder="%s"></p><p><input placeholder="%s"></p><p><input placeholder="%s"></p>'
                '<details open><summary>%s</summary><p><input dir="rtl" lang="ar" placeholder="%s"></p>'
                '<p><select><option>%s</option><option>%s</option><option>%s</option></select> <label>%s <input type="date"></label></p></details>'
                '<p><button class="button button-primary">%s</button></p></form>'
                '<div class="notice notice-info inline"><p>%s</p></div>') % (
                    esc(T('الموظفين', 'Employees')), esc(T('إضافة موظف', 'Add Employee')), esc(T('اسم الموظف', 'Employee Name')),
                    esc(T('اسم الدومين', 'Domain Name')), esc(T('البريد', 'Email')),
                    esc(T('بيانات الخطابات (اختياري)', 'Details for letters (optional)')), esc(T('الاسم بالعربي', 'Name in Arabic')),
                    esc(T('النوع: مش متسجل', 'Gender: not set')), esc(T('ذكر', 'Male')), esc(T('أنثى', 'Female')), esc(T('تاريخ التعيين', 'Hiring date')),
                    esc(T('إضافة', 'Add')),
                    esc(T('ملف الاستيراد (Excel/CSV) بقى ياخد ٣ أعمدة اختيارية: name_ar, gender (male/female), hire_date (YYYY-MM-DD).',
                          'The import file (Excel/CSV) takes three optional columns: name_ar, gender (male/female), hire_date (YYYY-MM-DD).')))
        return admin_page(T('الموظفين', 'Employees'), body, SUB, 'emp', feature_css=CSS)
    return make


def build():
    for slug, make in [('profile', profile()), ('profile-empty', profile(filled=False)), ('profile-edit', profile(edit=True)), ('add', add_form())]:
        write(F, slug, make)
    D = 'desk'
    gallery(F, 'Employee details for letters — mockups', (
        '<p>Three <b>optional</b> fields on an employee for HR letters (owner decision): <b>the name in Arabic</b>, <b>gender</b> and the '
        '<b>hiring date</b>. An Arabic letter uses the Arabic name; gender picks "Mr." or "Ms." and the matching wording; the hiring date '
        'is the "employed since" date. Left empty, a letter uses the employee name, wording that fits anyone, and the day the employee was '
        'added. Edited on the employee profile in wp-admin, in Add Employee and in the import file. Changes are audited.</p>'), [
        ('The employee profile', '', [('profile', 'Filled in', D), ('profile-empty', 'Not filled in yet (most employees after the update)', D),
                                      ('profile-edit', 'Editing them', D)]),
        ('Adding employees', '', [('add', 'Add Employee and the import columns', D)]),
    ])
