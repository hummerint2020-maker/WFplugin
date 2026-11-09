"""Occasions (3.31.145, docs/tasks/occasions.md): off changes nothing (no class, stylesheet, header or query more); switched on from
Feature Configuration (refused posts save nothing, one audit row, nothing written again when unchanged); the occasion on the app, the
login page and the kiosk (its header, never a name); not in wp-admin; the moment once a day; a last day; birthdays (one's own, a picked
person's); the words the administrator writes (escaped); Hide decorations; the preview; an employee cannot save the card.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_occasions.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import atexit, base64, datetime, html, json, os, re, sys, urllib.error, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
I = ids()
OPTS = ['ews_feature_occasions', 'ews_occasions', 'ews_employee_moments']
MOMENTS_BEFORE = php("echo wp_json_encode(get_option('ews_employee_moments',null));").splitlines()[-1]


@atexit.register
def cleanup():
    # whatever happens: the options, the meta and the extra employee go, and the moments come back as they were
    php("foreach(%s as $o) delete_option($o); $wpdb->query(\"DELETE FROM {$p}usermeta WHERE meta_key LIKE 'ews_occasion%%'\"); $wpdb->query(\"DELETE FROM {$p}ews_employees WHERE domain_name='occkhaled'\"); if($u=username_exists('occkhaled')){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($u);}" % json.dumps(OPTS).replace('[', 'array(').replace(']', ')'))
    if MOMENTS_BEFORE != 'null':
        php("update_option('ews_employee_moments',json_decode(base64_decode('%s'),true),false);" % base64.b64encode(MOMENTS_BEFORE.encode()).decode())


php("foreach(%s as $o) delete_option($o); $wpdb->query(\"DELETE FROM {$p}usermeta WHERE meta_key LIKE 'ews_occasion%%'\"); $wpdb->query(\"DELETE FROM {$p}ews_audit_log WHERE action='occasions_update'\");" % json.dumps(OPTS).replace('[', 'array(').replace(']', ')'))
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
FEAT = '/wp-admin/admin.php?page=ews31-features'
TODAY = php("echo current_time('Y-m-d');").splitlines()[-1]


def anon(url):
    try:
        r = urllib.request.urlopen(urllib.request.Request(B + url))
        return r.status, r.read().decode(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), dict(e.headers)


def form_fields(pg, action):
    form = [f for f in re.findall(r'<form.*?</form>', pg, re.S) if 'value="%s"' % action in f][0]
    data = []
    for tag in re.findall(r'<(?:input|select|textarea)[^>]*>', form):
        name = re.search(r'name="([^"]+)"', tag)
        if not name:
            continue
        typ = (re.search(r'type="([^"]+)"', tag) or [None, 'text'])[1]
        if typ in ('checkbox', 'radio') and 'checked' not in tag:
            continue
        if tag.startswith('<select'):
            sel = re.search(re.escape(tag) + r'(.*?)</select>', form, re.S).group(1)
            vals = re.findall(r'<option value="([^"]*)"[^>]*selected', sel)
            if not vals and 'multiple' not in tag:
                o = re.search(r'<option value="([^"]*)"', sel)
                vals = [o.group(1)] if o else ['']
            for v in vals:
                data.append((name.group(1), v))
            continue
        v = re.search(r'value="([^"]*)"', tag)
        data.append((name.group(1), html.unescape(v.group(1)) if v else ''))
    return data


def save(over=None, drop=(), sess=adm):
    fields = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if k not in drop and k not in (over or {})]
    for k, v in (over or {}).items():
        for vv in (v if isinstance(v, list) else [v]):
            fields.append((k, vv))
    st, body, h = sess.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
    return urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)


def opt(name):
    return json.loads(php("echo wp_json_encode(get_option('%s',null));" % name).splitlines()[-1])


def audits():
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='occasions_update' ORDER BY id")]


def kiosk(payload=False):
    url = '/?ews_kiosk=%s&kiosk_key=%s%s' % (I['kid'], I['secret'], '&kiosk_payload=1' if payload else '')
    return anon(url)


# ---- off
dash = emp.req('/app/')[1]
check('off: the app has no decoration, no stylesheet', 'wfo-occ' not in dash and 'occasions.css' not in dash)
st, login, _ = anon('/app/')
check('off: the login page has none', 'wfo-occ' not in login)
st, kpage, _ = kiosk()
check('off: the kiosk has none', st == 200 and 'wfo-occ' not in kpage and 'data-occasion' not in kpage, st)
st, body, h = kiosk(True)
check('off: no occasion header with the code', st == 200 and not any(k.lower() == 'x-wfo-occasion' for k in h), h)
fp = adm.req(FEAT)[1]
check('Features: the Occasions card, off', 'id="ews-occasions"' in fp and 'name="occasions_enabled"' in fp and not re.search(r'name="occasions_enabled" value="1" checked', fp))

# ---- switching on through the real form
before = audits()
qs = save({'occasions_enabled': '1', 'occ_active': 'ramadan', 'occ_until': '2001-01-01'})
check('a last day in the past is refused, nothing saved', qs.get('features_error') == ['occ_until_past'] and opt('ews_feature_occasions') in (None, 0, '0') and opt('ews_occasions') is None, (qs, opt('ews_occasions')))
qs = save({'occasions_enabled': '1', 'occ_active': 'ramadan', 'occ_text[ramadan][card_title][en]': 'x' * 61})
check('a word too long is refused', qs.get('features_error') == ['occ_text_long'] and opt('ews_occasions') is None, qs)
qs = save({'occasions_enabled': '1', 'occ_active': 'ramadan', 'occ_mode': 'bg', 'occ_kiosk': '1',
           'occ_text[ramadan][card_title][en]': 'Ramadan Kareem <script>alert(1)</script> team', 'occ_text[ramadan][card_line][en]': 'From us'})
o = opt('ews_occasions')
check('saved: on, Ramadan, started', qs.get('features_saved') and str(opt('ews_feature_occasions')) == '1' and o['active'] == 'ramadan' and o['started'] != '', (qs, o))
check('...one audit row', len(audits()) == len(before) + 1 and 'active=ramadan' in audits()[-1], audits())
save()
check('...saving again unchanged writes no audit row', len(audits()) == len(before) + 1)

# ---- on
php("delete_user_meta((int)get_user_by('login','emp1')->ID,'ews_occasion_moment');")
dash = emp.req('/app/')[1]
check('on: the app carries the occasion and its stylesheet', 'wfo-occ-on wfo-occ-ramadan' in dash and 'occasions.css' in dash and '.wfo-occ-ramadan{--wfo-occ-ink' in dash)
check('...the greeting card with the words written (escaped)', 'wfo-occ-greet' in dash and 'Ramadan Kareem alert(1) team' in html.unescape(dash) and '<script>alert(1)' not in dash, re.findall(r'<div class="wfo-occ-greet".*?wfo-occ-greet-art[^>]*></span></div>', dash, re.S)[:1])
check('...the moment the first time today', 'class="wfo-rm"' in dash and 'رمضان كريم' in dash)
dash2 = emp.req('/app/')[1]
check('...not again today', 'class="wfo-rm"' not in dash2 and 'wfo-occ-ramadan' in dash2)
tv = emp.req('/app/?ews_view=time')[1]
check('...other views: the decoration, no greeting card', 'wfo-occ-ramadan' in tv and 'wfo-occ-greet' not in tv)
st, login, _ = anon('/app/')
check('the login page shows the occasion (no name)', 'wfo-occ-ramadan' in login and 'wfo-occ-greet' in login)
st, kpage, _ = kiosk()
check('the kiosk shows it, behind the code', 'class="wfo-occ-on wfo-occ-ramadan k-occ"' in kpage and 'data-occasion="ramadan' in kpage and 'occasions.css' in kpage, kpage[:600])
st, body, h = kiosk(True)
hdr = {k.lower(): v for k, v in h.items()}.get('x-wfo-occasion', '')
check('...the code carries the occasion', urllib.parse.unquote(hdr).startswith('ramadan|') and body.startswith('wfo1|'), (hdr, body[:30]))
ap = adm.req('/wp-admin/admin.php?page=ews31-employees')[1]
check('never in wp-admin', 'wfo-occ-on' not in ap)

# ---- full screen, then the kiosk switch
save({'occ_mode': 'full'}, drop=('occ_kiosk',))
dash = emp.req('/app/')[1]
check('full screen: the falling layer, no layer in the top bar', 'wfo-occ is-full' in dash and 'wfo-occ is-bg' not in dash)
st, kpage, _ = kiosk()
check('kiosks off: no decoration there', 'wfo-occ' not in kpage)
save({'occ_mode': 'bg', 'occ_kiosk': '1'})

# ---- Hide decorations
dash = emp.req('/app/')[1]
m = re.search(r'href="([^"]*action=ews31_occasion_hide[^"]*)"', dash)
check('Hide decorations is offered', m is not None)
u = html.unescape(m.group(1)) if m else '/'
emp.req(u[len(B):] if u.startswith(B) else u)
dash = emp.req('/app/')[1]
check('...hidden for this employee', 'wfo-occ' not in dash)
check('...not for others (the login page)', 'wfo-occ-ramadan' in anon('/app/')[1])
st, _, _ = emp.req('/wp-admin/admin-post.php?action=ews31_occasion_hide&_wpnonce=bad')
check('...a hide without a valid nonce is refused', st in (403, 200) and 'wfo-occ' not in emp.req('/app/')[1])
save({'occ_active': 'halloween'})
dash = emp.req('/app/')[1]
check('the next occasion shows again', 'wfo-occ-halloween' in dash and 'class="wfo-hw"' in dash)

# ---- a last day
save({'occ_until': TODAY})
check('the last day itself: on', 'wfo-occ-halloween' in emp.req('/app/')[1])
php("$c=get_option('ews_occasions');$c['until']=gmdate('Y-m-d',strtotime(current_time('Y-m-d').' -1 day'));update_option('ews_occasions',$c,false);")
check('after the last day: gone everywhere', 'wfo-occ' not in emp.req('/app/')[1] and 'wfo-occ' not in kiosk()[1])
check('...Features says it ended', 'ended on' in adm.req(FEAT)[1])
save({'occ_until': ''})

# ---- birthdays
emp_id = int(I['eid'])
other = php("$u=username_exists('occkhaled')?:wp_create_user('occkhaled','occkhaledpass','occkhaled@example.com');$wpdb->query(\"DELETE FROM {$p}ews_employees WHERE domain_name='occkhaled'\");"
            "$wpdb->insert($p.'ews_employees',['name'=>'Khaled Youssef','domain_name'=>'occkhaled','email'=>'occkhaled@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1]);echo $wpdb->insert_id;").splitlines()[-1]
bday = '1990' + TODAY[4:]
php("update_option('ews_employee_moments',[%d=>['birthday'=>'%s','join_date'=>''],%s=>['birthday'=>'1970%s','join_date'=>'']],false);" % (emp_id, bday, other, TODAY[4:]))
php("delete_user_meta((int)get_user_by('login','emp1')->ID,'ews_occasion_moment');")
save({'occ_birthdays': '1', 'occ_cake_self': '1', 'occ_text[birthday][card_title][en]': 'Go {name}!'})
dash = emp.req('/app/')[1]
first = php("echo strtok(get_user_by('login','emp1')->display_name,' ');").splitlines()[-1]
check('my birthday: the birthday decorations and the cake', 'wfo-occ-birthday' in dash and 'class="wfo-bday"' in dash and 'HAPPY' in dash)
check('...my words with my name', 'Go ' in dash and '{name}' not in dash)
st, login, _ = anon('/app/')
check('...never on the login page (the company occasion there)', 'wfo-occ-halloween' in login and 'Go ' not in login)
khaled = Session('occkhaled', 'occkhaledpass')
kd = khaled.req('/app/?ews_view=dashboard')[1]
card = ''.join(re.findall(r'<div class="wfo-occ-greet".*?wfo-occ-greet-art[^>]*></span></div>', kd, re.S))
check('a colleague does not see my birthday (theirs is today too: they see their own)', first not in card and 'Khaled' in card, (card, re.findall(r'<title>[^<]*', kd)[:1], 'wfo-occ-greet' in kd, kd[kd.find('wfo-content'):kd.find('wfo-content')+400] if not card else ''))
save({'occ_people[]': other, 'occ_cake_everyone': '1'})
php("delete_user_meta((int)get_user_by('login','emp1')->ID,'ews_occasion_moment');")
php("update_option('ews_employee_moments',[%s=>['birthday'=>'1970%s','join_date'=>'']],false);" % (other, TODAY[4:]))
dash = emp.req('/app/')[1]
check('a picked person\'s birthday: everyone sees it, with the name and the cake', 'wfo-occ-birthday' in dash and 'Khaled' in dash and 'class="wfo-bday"' in dash, re.findall(r'<div class="wfo-occ-greet".*?wfo-occ-greet-art[^>]*></span></div>', dash, re.S)[:1])
kd = khaled.req('/app/?ews_view=dashboard')[1]
check('...the person sees their own birthday', 'wfo-occ-birthday' in kd)
check('...not on a kiosk', 'Khaled' not in kiosk()[1])

# ---- preview and access
php("update_option('ews_feature_occasions',0,false);")
check('off again: gone', 'wfo-occ' not in emp.req('/app/')[1])
pv = adm.req('/app/?wfo_occasion_preview=new_year')[1]
check('an administrator\'s preview works while off', 'wfo-occ-new_year' in pv and 'class="wfo-mo wfo-mo-new_year"' in pv)
check('...an employee\'s preview is ignored', 'wfo-occ' not in emp.req('/app/?wfo_occasion_preview=new_year')[1])
o_before = opt('ews_occasions')
st, body, h = emp.req('/wp-admin/admin-post.php', urllib.parse.urlencode(form_fields(adm.req(FEAT)[1], 'ews31_features_save') + [('occ_active', 'valentine')]).encode())
check('an employee cannot save the card', opt('ews_occasions') == o_before)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
