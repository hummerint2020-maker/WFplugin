"""Behaviour tests for Employee Polls: wp-admin → Employee Polls (create, audience, results visibility,
changing a vote, anonymous or named voting, notifications, participation, export, archive) and the
employee app (the Dashboard card and the Polls page).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_polls.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import csv, html, io, json, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

PAGE = '/wp-admin/admin.php?page=ews31-polls'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_polls','ews_poll_options','ews_poll_votes','ews_departments','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ops','active'=>1]); $ops=$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'sales','active'=>1]); $sales=$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Emp One','department_id'=>$ops],['id'=>$eid]);
        $mk=function($login,$name,$dep)use($wpdb,$p){$u=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>1,'department_id'=>$dep]);return $wpdb->insert_id;};
        echo wp_json_encode(['emp'=>$eid,'ola'=>$mk('ola','Ola Ops',$ops),'sam'=>$mk('sam','Sam Sales',$sales),'ops'=>$ops,'sales'=>$sales]);
    """)
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def field(form, name):
    m = re.search(r'name="%s" value="([^"]*)"' % re.escape(name), form)
    return html.unescape(m.group(1)) if m else ''


def notices(page):
    return html.unescape(' '.join(re.sub(r'<[^>]+>', '', n).strip() for n in re.findall(r'<div class="(?:notice|ews-poll-error|ews-poll-success)[^"]*"[^>]*>(.*?)</div>', page, re.S)))


def poll_id(question):
    r = q("SELECT id FROM {p}ews_polls WHERE question='%s'" % question)
    return int(r[0]['id']) if r else 0


def choice_id(pid, text):
    return int(q("SELECT id FROM {p}ews_poll_options WHERE poll_id=%d AND option_text='%s'" % (pid, text))[0]['id'])


def cards(page):
    """Poll cards on an app page: id → {state, voted?, results{choice: votes}}."""
    out = {}
    for m in re.finditer(r'<section class="ews-poll-card[^"]*" data-poll="(\d+)" data-state="([a-z]+)"(.*?)</section>', page, re.S):
        body = m.group(3)
        out[int(m.group(1))] = {'state': m.group(2), 'form': 'name="action" value="ews_poll_vote"' in body,
                                'results': {html.unescape(c): int(v) for c, v in re.findall(r'data-choice="([^"]*)" data-votes="(\d+)"', body)}, 'body': body}
    return out


ids = seed()
adm = Session('admin', 'admin')
st, page, _ = adm.req(PAGE)
f = forms(page, 'ews_poll_save')
check('the Polls page opens with audience, results, vote-change, anonymity and notify settings',
      f and all('name="%s"' % n in f[0] for n in ('audience_department_id', 'results_visibility', 'allow_change', 'anonymous', 'notify')), f[0][:300] if f else page[:300])
check('the page has no inline script or style', '<script>' not in page.split('id="wpbody-content"')[-1].split('id="wpfooter"')[0] and '.wfo-poll-admin{' not in page)
n = field(f[0], 'ews_poll_nonce') if f else ''


def save(**fields):
    data = {'action': 'ews_poll_save', 'ews_poll_nonce': n, 'poll_id': 0, 'question': '', 'description': '', 'active': '1', 'show_homepage': '1', 'starts_at': '', 'ends_at': '',
            'audience_department_id': 0, 'results_visibility': 'after_vote'}
    data.update({k: v if isinstance(v, list) else str(v) for k, v in fields.items()})
    st, body, h = adm.req('/wp-admin/admin-post.php', data)
    return html.unescape(h.get('Location', '')).split('127.0.0.1:8080')[-1], body


loc, _ = save(question='Lunch on Thursday?', **{'options[]': ['Koshary']})
check('a poll needs at least two choices, and says so', 'A poll needs a question and at least two choices.' in notices(adm.req(loc)[1]) and not poll_id('Lunch on Thursday?'))
loc, _ = save(question='Lunch on Thursday?', description='Team lunch', allow_change='1', anonymous='1', notify='1', **{'options[]': ['Koshary', 'Pizza', 'Grills']})
p1 = poll_id('Lunch on Thursday?')
check('a poll is created', 'poll_saved=1' in loc and p1)
check('...recorded in the Audit Log as created', [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='poll_saved'")] == ['Created employee poll: Lunch on Thursday?'])
note = q("SELECT user_id FROM {p}ews_notifications WHERE title='New poll'")
check('...and everyone in its audience is told it is open (3 employees)', len(note) == 3, note)

# ---------------------------------------------------------------- voting
emp, sam = Session('emp1', 'emp1pass'), Session('sam', 'sampass')
st, page, _ = emp.req('/app/')
c = cards(page)
check('the Dashboard shows the open poll with its choices', c.get(p1, {}).get('form') and 'Koshary' in c[p1]['body'] and 'Team lunch' in c[p1]['body'], c)
vote_form = forms(page, 'ews_poll_vote')[0] if forms(page, 'ews_poll_vote') else ''


NONCES = {}


def vote(sess, pid, choice, page_html=None):
    """Vote with the poll's own form nonce (remembered from when the form was last shown)."""
    page_html = page_html or sess.req('/app/?ews_view=polls')[1]
    form = next((x for x in forms(page_html, 'ews_poll_vote') if 'name="poll_id" value="%d"' % pid in x), '')
    if form:
        NONCES[(id(sess), pid)] = field(form, 'ews_poll_nonce')
    st, _, h = sess.req('/wp-admin/admin-post.php', {'action': 'ews_poll_vote', 'ews_poll_nonce': NONCES.get((id(sess), pid), 'none'), 'poll_id': pid, 'option_id': choice})
    return html.unescape(h.get('Location', '')).split('127.0.0.1:8080')[-1]


loc = vote(emp, p1, choice_id(p1, 'Pizza'), page)
st, page, _ = emp.req(loc)
check('after voting the results are shown', cards(page).get(p1, {}).get('results') == {'Koshary': 0, 'Pizza': 1, 'Grills': 0}, cards(page).get(p1))
check('...with a thank-you', 'Thanks for voting!' in notices(page))
check('...and, since changing is allowed, a form to change the vote', cards(page)[p1]['form'] and 'Change vote' in cards(page)[p1]['body'])
vote(emp, p1, choice_id(p1, 'Grills'))
check('changing the vote moves it (still one vote)', [(int(r['option_id']) == choice_id(p1, 'Grills')) for r in q("SELECT option_id FROM {p}ews_poll_votes WHERE poll_id=%d" % p1)] == [True])

loc, _ = save(question='Offsite in Alexandria?', audience_department_id=ids['ops'], results_visibility='after_close', ends_at='2030-01-01T12:00', notify='1', **{'options[]': ['Yes', 'No']})
p2 = poll_id('Offsite in Alexandria?')
check('a department poll notifies only that department (2)', len(q("SELECT id FROM {p}ews_notifications WHERE title='New poll' AND entity_id=%d" % p2)) == 2)
st, page, _ = sam.req('/app/?ews_view=polls')
check('another department does not see it', p2 not in cards(page) and p1 in cards(page), list(cards(page)))
loc = vote(sam, p2, choice_id(p2, 'Yes'))
check('...and cannot vote in it (no form, so no valid request)', not q("SELECT id FROM {p}ews_poll_votes WHERE poll_id=%d" % p2))
loc = vote(emp, p2, choice_id(p2, 'Yes'))
st, page, _ = emp.req(loc)
check('results shown "when the poll closes" stay hidden after voting', cards(page).get(p2, {}).get('results') == {} and 'Results will be shown when the poll closes' in cards(page)[p2]['body'], cards(page).get(p2))
loc = vote(emp, p2, choice_id(p2, 'No'))
check('without vote changes, a second vote is refused', 'You have already voted in this poll.' in notices(emp.req(loc)[1]) and len(q("SELECT id FROM {p}ews_poll_votes WHERE poll_id=%d" % p2)) == 1)
check('the Polls page says when a poll closes', 'Closes in' in cards(emp.req('/app/?ews_view=polls')[1])[p2]['body'])

# ---------------------------------------------------------------- admin view
st, page, _ = adm.req(PAGE)
row1 = re.search(r'<tr data-poll="%d".*?</tr>' % p1, page, re.S)
row2 = re.search(r'<tr data-poll="%d".*?</tr>' % p2, page, re.S)
check('admins see participation: 1 of 3 employees', row1 and '1 of 3 voted (33%)' in row1.group(0), row1.group(0)[:500] if row1 else page[:300])
check('...and of a department poll: 1 of 2', row2 and '1 of 2 voted (50%)' in row2.group(0) and 'Ops' in row2.group(0))
check('...and its results, even before it closes', row2 and re.search(r'Yes</span><b>100%', row2.group(0)) is not None)


def export(pid):
    m = re.search(r'href="([^"]*action=ews_poll_export[^"]*poll_id=%d[^"]*)"' % pid, page) or re.search(r'href="([^"]*poll_id=%d[^"]*action=ews_poll_export[^"]*)"' % pid, page)
    st, body, _ = adm.req(html.unescape(m.group(1)).split('127.0.0.1:8080')[-1]) if m else (0, '', {})
    return body.lstrip('﻿') if st == 200 else ''


e2 = export(p2)
check('a named poll exports who voted for what', 'Emp One' in e2 and 'Yes' in e2, e2[:300])
e1 = export(p1)
check('an anonymous poll exports the counts only, no names', 'Grills' in e1 and 'Emp One' not in e1, e1[:300])
loc, _ = save(poll_id=p1, question='Lunch on Thursday?', allow_change='1', anonymous='1', **{'options[]': ['Koshary', 'Pizza']})
check('choices are locked once employees have voted', 'Choices cannot be changed' in notices(adm.req(loc)[1]) and len(q("SELECT id FROM {p}ews_poll_options WHERE poll_id=%d" % p1)) == 3)

# ---------------------------------------------------------------- closing, archiving, hidden and scheduled polls
php("$wpdb->update($p.'ews_polls',['ends_at'=>'2020-01-01 00:00:00'],['id'=>%d]);" % p2)
st, page, _ = emp.req('/app/?ews_view=polls')
check('once closed, its results are shown to its audience', cards(page).get(p2, {}).get('state') == 'closed' and cards(page)[p2]['results'] == {'Yes': 1, 'No': 0}, cards(page).get(p2))
loc = vote(emp, p2, choice_id(p2, 'No'), page)
check('...and nobody can vote any more', 'This poll is closed.' in notices(emp.req(loc)[1]))
st, page, _ = adm.req(PAGE)
arch = re.search(r'<a [^>]*href="([^"]*action=ews_poll_archive[^"]*poll_id=%d[^"]*)"[^>]*>' % p1, page)
check('archiving asks for confirmation (no inline script)', arch and 'data-ews-confirm=' in arch.group(0) and 'onclick' not in arch.group(0))
adm.req(html.unescape(arch.group(1)).split('127.0.0.1:8080')[-1]) if arch else None
check('an archived poll keeps its votes and shows under past polls', q("SELECT status FROM {p}ews_polls WHERE id=%d" % p1)[0]['status'] == 'archived' and cards(emp.req('/app/?ews_view=polls')[1]).get(p1, {}).get('state') == 'archived')
save(question='Hidden one?', active='0', **{'options[]': ['A', 'B']})
save(question='Next month?', starts_at='2030-01-01T09:00', **{'options[]': ['A', 'B']})
st, page, _ = emp.req('/app/?ews_view=polls')
check('hidden and not-yet-started polls are not shown', poll_id('Hidden one?') not in cards(page) and poll_id('Next month?') not in cards(page))
vote(emp, poll_id('Hidden one?'), choice_id(poll_id('Hidden one?'), 'A'), page)
check('...and cannot be voted in', not q("SELECT id FROM {p}ews_poll_votes WHERE poll_id=%d" % poll_id('Hidden one?')))
php("$wpdb->update($p.'ews_polls',['status'=>'inactive'],['id'=>%d]);" % poll_id('Offsite in Alexandria?'))
check('a poll switched off after its form was shown refuses the vote, and says why', 'This poll is closed.' in notices(emp.req(vote(emp, p2, choice_id(p2, 'No'), page))[1]))
save(question='Coffee machine?', **{'options[]': ['Yes', 'No']})
st, page, _ = emp.req('/app/')
c = cards(page)
check('the Dashboard shows the newest poll the employee has not answered, with a link to all polls', list(c) == [poll_id('Coffee machine?')] and 'ews_view=polls' in page, list(c))

st, page, _ = emp.req('/app/?ews_view=polls')
content = page.split('<div class="wfo-content">', 1)[-1]
check('no emoji on the Polls page or its cards', not re.search('[\U0001F300-\U0001FAFF\u2600-\u27BF\u23F3]', content), re.findall('[\U0001F300-\U0001FAFF\u2600-\u27BF\u23F3]', content))
check('the poll stylesheet is loaded in the head of the Polls page and Home', 'app-polls.css' in page.split('</head>')[0] and 'app-polls.css' in emp.req('/app/')[1].split('</head>')[0])
check('the choices are a labelled group (fieldset + legend)', '<fieldset class="ews-poll-options' in content and '<legend class="screen-reader-text">' in content)

# ---------------------------------------------------------------- access
st, body, _ = emp.req(PAGE)
check('an employee cannot open the admin page', 'Create New Poll' not in body)
before = len(q("SELECT id FROM {p}ews_polls"))
emp.req('/wp-admin/admin-post.php', {'action': 'ews_poll_save', 'ews_poll_nonce': n, 'question': 'Sneaky?', 'options[]': ['A', 'B']})
check('...or create a poll', len(q("SELECT id FROM {p}ews_polls")) == before)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
