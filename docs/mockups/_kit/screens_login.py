"""Login page helpers (owner request 2026-11-21, after the empty-fields fix): on the app's own login page,
check the two fields before sending, keep the username after a wrong password, a "Signing in..." button and a
Caps Lock line. One switch in wp-admin -> Appearance -> Login screen, off by default.
The card is the real templates/app/login.php markup with the real app-login.css; login-assist.css is what Step 2 ships."""
from build import T, Lang, esc, icon, css, page_doc, write, gallery, Build

F = 'login-assist'


def card(user='', err='', field_err='', caps=False, busy=False, focus=''):
    def field(kind, value, ph, ac, fe):
        bad = ' is-invalid' if fe else ''
        f = ' is-focus' if focus == kind else ''
        typ = 'password' if kind == 'password' else 'text'
        inp = '<input type="%s" name="%s" id="ews_login_%s" placeholder="%s" autocomplete="%s" class="input%s%s" value="%s"%s>' % (
            typ, 'pwd' if typ == 'password' else 'log', kind, esc(ph), ac, bad, f, esc(value), ' aria-invalid="true"' if fe else '')
        if typ == 'password':
            inp = '<span class="wfo-login-pass">%s<button type="button" class="wfo-login-eye" aria-pressed="false">%s</button></span>' % (inp, icon('eye', 18, 2))
        msg = '<span class="wfo-login-field-msg">%s%s</span>' % (icon('alert', 14, 2), esc(fe)) if fe else ''
        if kind == 'password' and caps:
            msg += '<span class="wfo-login-caps">%s%s</span>' % (icon('capslock', 14, 2.2), esc(T('زرار Caps Lock شغال', 'Caps Lock is on')))
        return '<p class="login-%s"><label for="ews_login_%s">%s</label>%s%s</p>' % ('username' if kind == 'username' else 'password', kind, esc(ph), inp, msg)
    btn = ('<button type="submit" class="wfo-login-submit is-busy" disabled><span class="wfo-login-spin" aria-hidden="true"></span>%s</button>' % esc(T('جاري الدخول...', 'Signing in...'))
           if busy else '<input type="submit" class="button button-primary" value="%s">' % esc(T('تسجيل الدخول', 'Sign In')))
    banner = '<div class="ews-login-error" role="alert">%s<span>%s</span></div>' % (icon('alert', 16), esc(err)) if err else ''
    return ('<div class="ews-login-wrap wfo-login is-bg-theme"><div class="wfo-login-inner">'
            '<div class="wfo-login-brand"><span class="wfo-login-mark">WO</span><strong class="ews-login-logo">Workforce One</strong><span class="wfo-login-line">%s</span></div>'
            '<div class="ews-login-card wfo-login-card has-eye"><h1>%s</h1><p class="ews-login-sub">%s</p>%s'
            '<form>%s%s<p class="ews-login-forgot"><a href="#">%s</a></p><p class="login-remember"><label><input type="checkbox"> %s</label></p><p class="login-submit">%s</p></form>'
            '</div></div></div>') % (
                esc(T('فريق BA · منصة واحدة. فريق واحد. هدف واحد.', 'BA Team · One Platform. One Team. One Goal.')),
                esc(T('أهلاً بيك تاني', 'Welcome back')), esc(T('ادخل بحساب الشغل', 'Sign in with your work account')), banner,
                field('username', user, T('اسم المستخدم أو الإيميل', 'Username or Email'), 'username', field_err if field_err and not user else ''),
                field('password', '••••••••' if busy or caps else '', T('كلمة المرور', 'Password'), 'current-password', field_err if field_err and user else ''),
                esc(T('نسيت كلمة المرور؟', 'Forgot password?')), esc(T('افتكرني', 'Remember me')), btn)


def page(**kw):
    def make():
        head = css('app-login.css') + '<link rel="stylesheet" href="%s">' % ('login-assist-rtl.css' if Lang.rtl() else 'login-assist.css')
        return page_doc(T('تسجيل الدخول', 'Sign in'), head, '<body style="margin:0">%s</body>' % card(**kw))
    return make


def build():
    write(F, 'empty', page(field_err=T('اكتب اسم المستخدم أو الإيميل', 'Enter your username or email'), focus='username'))
    write(F, 'empty-pass', page(user='mona.adel', field_err=T('اكتب كلمة المرور', 'Enter your password'), focus='password'))
    write(F, 'wrong', page(user='mona.adel', err=T('اسم المستخدم أو كلمة المرور غلط. جرب تاني.', 'The username or password is incorrect. Please try again.'), focus='password'))
    write(F, 'caps', page(user='mona.adel', caps=True, focus='password'))
    write(F, 'busy', page(user='mona.adel', busy=True))
    gallery(F, 'Login page helpers — mockups (Step 1)',
            '<p>Four small helpers on the app\'s own login page. <b>One switch</b> in wp-admin → Appearance → Login screen, '
            '<b>"Sign-in helpers"</b>, <b>off by default</b>: while it is off the page is exactly as today. '
            'The fix for an empty form (it now comes back to this page, not wp-login.php) is separate and always on.</p>'
            '<p>The password is never kept anywhere. The username is kept only in this browser tab (sessionStorage) until the sign-in works.</p>',
            [('Before sending', 'Checked on the phone, no page reload; the cursor goes to the empty field.',
              [('empty', 'Nothing typed: the username field asks for itself', 'phone'), ('empty-pass', 'Username typed, no password', 'phone')]),
             ('After a wrong password', None, [('wrong', 'The username stays typed; the cursor is in the password', 'phone')]),
             ('While typing the password', None, [('caps', 'Caps Lock is on', 'phone')]),
             ('After tapping Sign In', 'One tap only: the button waits, so a second tap on a slow connection does nothing.', [('busy', 'Signing in...', 'phone')])])


if __name__ == '__main__':
    import os, sys
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    build()
