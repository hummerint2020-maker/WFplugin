"""System messages (3.31.166, docs/tasks/system-messages.md): off by default and then changes nothing; the Features switch;
who may open the page; saving a text in English and in Arabic and the site showing it; refused texts (placeholders, a lone %,
tags, a save over someone else's); back to default (one, a module, a whole language); a saved text whose original is gone.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_system_messages.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import hashlib, html, json, os, re, sys, urllib.parse
from html.parser import HTMLParser

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-system-messages'
FEATURES = '/wp-admin/admin.php?page=ews31-features'
KEY = 'Correction rejected'
PH = 'Your correction for %1$s was rejected: %2$s'


def h(key):
    return hashlib.md5(key.encode('utf-8')).hexdigest()


def last(out):
    lines = (out or '').splitlines()
    return lines[-1] if lines else ''


def lit(s):
    """A PHP single-quoted literal (no $ interpolation)."""
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


# The site in Arabic for this request (the test site has no Arabic language pack, so switch_to_locale() would refuse).
AS_AR = "add_filter('locale',function(){return 'ar';}); do_action('change_locale','ar');"


def text(key, locale=''):
    """The text as the site shows it now (a fresh request), in English or in Arabic."""
    sw = AS_AR if locale else ''
    return last(php(sw + "echo wp_json_encode(__(%s,'workforce-one'));" % lit(key)))


def overrides():
    return json.loads(last(php("echo wp_json_encode(get_option('ews_message_overrides',null));")) or 'null')


def forms(page):
    """Each post form's fields as the browser would send them (hidden inputs, the textarea)."""
    out = []
    for chunk in re.findall(r'<form method="post".*?</form>', page, re.S):
        f = Form()
        f.feed(chunk)
        out.append(f.data)
    return out


def edit_form(sess, key, lang):
    st, page, _ = sess.req(PAGE + '&lang=%s&edit=%s' % (lang, h(key)))
    for f in forms(page):
        if ('action', 'ews_messages_save') in f:
            return dict(f), page
    return {}, page


def save(sess, key, lang, new, **over):
    f, _ = edit_form(sess, key, lang)
    f['text'] = new
    f.update(over)
    st, body, hd = sess.req('/wp-admin/admin-post.php', {k: v for k, v in f.items() if v is not None})
    return st, hd.get('Location', '')


def reset(sess, scope, lang='en', **extra):
    st, page, _ = sess.req(PAGE + '&lang=' + lang + ''.join('&%s=%s' % (k, urllib.parse.quote(str(v))) for k, v in extra.get('get', {}).items()))
    for f in forms(page):
        d = dict(f)
        if d.get('action') == 'ews_messages_reset' and d.get('scope') == scope:
            d.update(extra.get('post', {}))
            st, body, hd = sess.req('/wp-admin/admin-post.php', d)
            return hd.get('Location', '')
    return 'no form'


class Form(HTMLParser):
    """The Features form's fields as the browser would send them."""
    def __init__(self):
        super().__init__()
        self.inside, self.data, self.area, self.select = False, [], None, None

    def handle_starttag(self, tag, a):
        a = dict(a)
        if tag == 'form':
            self.inside = True
        if not self.inside:
            return
        if tag == 'input' and a.get('name'):
            t = a.get('type', 'text')
            if t in ('checkbox', 'radio') and 'checked' not in a:
                return
            self.data.append((a['name'], a.get('value', 'on' if t == 'checkbox' else '')))
        elif tag == 'textarea' and a.get('name'):
            self.area = [a['name'], '']
        elif tag == 'select' and a.get('name'):
            self.select = [a['name'], None, None]
        elif tag == 'option' and self.select:
            if self.select[2] is None:
                self.select[2] = a.get('value', '')
            if 'selected' in a:
                self.select[1] = a.get('value', '')

    def handle_data(self, d):
        if self.area is not None:
            self.area[1] += d

    def handle_endtag(self, tag):
        if tag == 'textarea' and self.area is not None:
            self.data.append((self.area[0], html.unescape(self.area[1])))
            self.area = None
        elif tag == 'select' and self.select:
            self.data.append((self.select[0], self.select[1] if self.select[1] is not None else self.select[2]))
            self.select = None
        elif tag == 'form':
            self.inside = False


def features_form(sess):
    st, page, _ = sess.req(FEATURES)
    f = Form()
    f.feed(page[page.index('<form method="post"'):] if '<form method="post"' in page else '')
    return [(k, v) for k, v in f.data if k != 'system_messages_enabled']


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""$wpdb->query("DELETE FROM {$p}ews_audit_log"); delete_option('ews_message_overrides'); delete_option('ews_feature_system_messages');""")
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- off by default: nothing changes
st, page, _ = adm.req(FEATURES)
check('Features shows the System messages section, off', 'id="ews-system-messages"' in page and 'name="system_messages_enabled" value="1" checked' not in page
      and 'name="system_messages_present"' in page)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31')
check('off: no System messages item in the menu', 'ews31-system-messages' not in page)
st, _, _ = adm.req(PAGE)
check('off: the page cannot be opened', st != 200, st)
php("update_option('ews_message_overrides',['en'=>[%s=>'Turned down']],false);" % lit(KEY))
check('off: a saved text is not shown', text(KEY) == json.dumps(KEY))
check('off: no gettext filter is added', last(php("echo (int)has_filter('gettext_workforce-one').(int)has_filter('load_script_translations');")) == '00')
php("delete_option('ews_message_overrides');")

# ---------------------------------------------------------------- the switch
fields = features_form(adm)
before = last(php("echo wp_json_encode([get_option('ews_feature_tasks'),get_option('ews_breaks_per_day')]);"))
st, _, hd = adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields + [('system_messages_enabled', '1')]).encode())
check('turned on from Features', last(php("echo (int)get_option('ews_feature_system_messages');")) == '1', hd.get('Location'))
check('...the other features are kept', last(php("echo wp_json_encode([get_option('ews_feature_tasks'),get_option('ews_breaks_per_day')]);")) == before)
check('...written to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='system_messages_feature_update'")) == 1)
check('...the administrator has the new permission', last(php("echo (int)user_can(get_user_by('login','admin'),'ews_manage_messages');")) == '1')
check('on with nothing edited: still no gettext filter', last(php("echo (int)has_filter('gettext_workforce-one');")) == '0')

# ---------------------------------------------------------------- who may open it
st, page, _ = adm.req(PAGE + '&lang=en')
check('the page opens for an administrator, with every text', st == 200 and 'data-sm-page' in page and 'data-sm-table' in page, st)
st, _, _ = emp.req(PAGE)
check('an employee cannot open it', st != 200)
f, _ = edit_form(adm, KEY, 'en')
st, body, _ = emp.req('/wp-admin/admin-post.php', dict(f, text='Hacked'))
check('...nor save a text', overrides() is None, st)

# ---------------------------------------------------------------- saving
st, loc = save(adm, KEY, 'en', 'Turned down')
check('an English text is saved', 'sm_done=saved' in loc and overrides() == {'en': {KEY: 'Turned down'}}, (loc, overrides()))
check('...the site shows it', text(KEY) == '"Turned down"')
check('...not in Arabic', text(KEY, 'ar') != '"Turned down"')
a = q("SELECT details FROM {p}ews_audit_log WHERE action='system_message_save'")
check('...written to the Audit Log with before and after', len(a) == 1 and 'Turned down' in a[0]['details'], a)
st, page, _ = adm.req(PAGE + '&lang=en&edited=1')
check('the list shows it as edited, first', 'data-sm-row="%s"' % h(KEY) in page and 'data-sm-edited' in page)

st, loc = save(adm, PH, 'ar', 'رفض طلب تصحيح %1$s: %2$s')
check('an Arabic text with its values is saved', 'sm_done=saved' in loc and overrides().get('ar', {}).get(PH) == 'رفض طلب تصحيح %1$s: %2$s', (loc, overrides()))
check('...the site shows it in Arabic', json.loads(text(PH, 'ar')) == 'رفض طلب تصحيح %1$s: %2$s')
check('...and the values still fill in', last(php(AS_AR + "echo sprintf(__(%s,'workforce-one'),'1/1','x');" % lit(PH))) == 'رفض طلب تصحيح 1/1: x')
check('...the English is untouched', text(PH) == json.dumps(PH))

# ---------------------------------------------------------------- refused
for name, new, code in [('a missing value', 'Rejected: %2$s', 'placeholders'), ('an extra value', '%1$s %2$s %3$s', 'placeholders'),
                        ('a lone %', '%1$s %2$s 100%', 'percent'), ('a tag', '<b>%1$s</b> %2$s', 'html'), ('an empty text', '  ', 'empty')]:
    st, loc = save(adm, PH, 'en', new)
    check('refused: ' + name, 'sm_error=' + code in loc and PH not in (overrides() or {}).get('en', {}), loc)
st, loc = save(adm, PH, 'en', 'Rejected: %2$s')
st, page, _ = adm.req(loc.replace('http://127.0.0.1:8080', ''))
check('...the panel comes back with what was typed and why', 'data-sm-error' in page and 'Rejected: %2$s' in page)
f, _ = edit_form(adm, KEY, 'en')
php("$o=get_option('ews_message_overrides'); $o['en'][%s]='Changed meanwhile'; update_option('ews_message_overrides',$o,false);" % lit(KEY))
st, body, hd = adm.req('/wp-admin/admin-post.php', dict(f, text='Mine'))
check('a save over a text someone else changed meanwhile is refused', 'sm_error=stale' in hd.get('Location', '') and overrides()['en'][KEY] == 'Changed meanwhile', hd.get('Location'))

# ---------------------------------------------------------------- back to default
st, loc = save(adm, KEY, 'en', 'Correction rejected')
check('saving the default text is a reset', 'sm_done=reset' in loc and KEY not in overrides().get('en', {}), (loc, overrides()))
save(adm, KEY, 'en', 'Turned down')
save(adm, 'Leave Approved', 'en', 'Leave OK')
loc = reset(adm, 'module', 'en', get={'module': 'attendance'})
check('a module goes back to the default, the others stay', 'sm_done=reset_many' in loc and KEY not in overrides().get('en', {}) and overrides()['en'].get('Leave Approved') == 'Leave OK', (loc, overrides()))
loc = reset(adm, 'all', 'en')
check('a whole language goes back, the other language stays', 'sm_done=reset_many' in loc and 'en' not in overrides() and PH in overrides().get('ar', {}), (loc, overrides()))
check('resets are written to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='system_message_reset'")) >= 2)

# ---------------------------------------------------------------- a text that is no longer in the plugin
php("$o=get_option('ews_message_overrides'); $o['en']['A text an update removed']='Old words'; update_option('ews_message_overrides',$o,false);")
st, page, _ = adm.req(PAGE + '&lang=en')
check('a saved text whose original is gone is listed under Needs review', 'data-sm-review' in page and 'A text an update removed' in page)
loc = reset(adm, 'one_review', 'en', post={})
check('...and can be removed', 'sm_done=reset_many' in loc and 'en' not in overrides(), (loc, overrides()))

# ---------------------------------------------------------------- off again: the shipped texts, the edits kept
php("update_option('ews_feature_system_messages',0,false);")
check('turned off: the shipped text is shown again', json.loads(text(PH, 'ar')) != 'رفض طلب تصحيح %1$s: %2$s')
check('...and the edits are kept for later', PH in (overrides() or {}).get('ar', {}))

php("delete_option('ews_message_overrides'); delete_option('ews_feature_system_messages');")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
