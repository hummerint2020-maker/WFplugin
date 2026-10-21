"""Behaviour tests for Tasks, more (3.31.70): wp-admin → Tasks settings, the task details sheet
(checklist, comments with a private file, activity), team tasks (a copy each / the first one takes
it), repeating tasks and reminders (the 5-minute run), Workload, My tasks today on Home, dragging.

Usage: python3 tests/e2e_tasks_more.py <state dir>   (after tests/e2e_setup.php)
"""
import html, os, re, sys, uuid, urllib.parse, urllib.request, urllib.error
from datetime import date, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results  # noqa: E402

I = ids()
PAGE = '/app/?ews_view=tasks'
today = date.today()
yesterday = (today - timedelta(days=1)).isoformat()
php("""update_option('ews_feature_tasks',1,false); delete_option('ews_tasks_settings');
foreach(['ews_tasks','ews_task_items','ews_task_comments','ews_task_activity'] as $t)$wpdb->query("DELETE FROM {$p}$t");
$wpdb->query("DELETE FROM {$p}ews_notifications WHERE entity='task'");""")
# A second employee and a team with both.
ids2 = php("""
$u=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
wp_set_password('emp2pass',$u);
$e=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%%d OR domain_name='emp2' ORDER BY id LIMIT 1",$u));
if($e){$wpdb->update($p.'ews_employees',['name'=>'Emp Two','wp_user_id'=>$u,'active'=>1],['id'=>$e]);}else{$wpdb->insert($p.'ews_employees',['name'=>'Emp Two','domain_name'=>'emp2','email'=>'emp2@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>0]);$e=(int)$wpdb->insert_id;}
$wpdb->query("DELETE FROM {$p}ews_teams WHERE name='Front desk'");
$wpdb->insert($p.'ews_teams',['name'=>'Front desk','active'=>1]);$tm=(int)$wpdb->insert_id;
foreach([%d,$e] as $x)$wpdb->insert($p.'ews_team_members',['team_id'=>$tm,'employee_id'=>$x,'active'=>1]);
echo $u.' '.$e.' '.$tm;""" % I['eid']).split()
U2, E2, TEAM = int(ids2[0]), int(ids2[1]), int(ids2[2])
emp, emp2, adm = Session('emp1', 'emp1pass'), Session('emp2', 'emp2pass'), Session('admin', 'admin')


def form_nonce(page, action, **match):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action not in form:
            continue
        if any('name="%s" value="%s"' % (k, v) not in form for k, v in match.items()):
            continue
        m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        if m:
            return m.group(1)
    return None


def task(title):
    rows = q("SELECT * FROM {p}ews_tasks WHERE title='%s' AND is_template=0 ORDER BY id" % title)
    return rows


def multipart(sess, fields, file_field=None, filename=None, content=b''):
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in fields.items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, v)).encode()
    if file_field:
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="%s"\r\nContent-Type: application/octet-stream\r\n\r\n' % (boundary, file_field, filename)).encode() + content + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = sess.op.open(req)
        loc = r.headers.get('Location', '')
    except urllib.error.HTTPError as e:
        loc = e.headers.get('Location', '')
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(loc).query).items()}


def get(sess, url):
    try:
        r = sess.op.open(urllib.request.Request(url if url.startswith('http') else B + url))
        return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


# ---------------------------------------------------------------- wp-admin → Tasks
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-tasks')
check('wp-admin → Tasks opens with every part switched on', st == 200 and page.count('class="wfo-feature-status"') >= 13 and 'name="attach_max_mb"' in page and 'admin-features.css' in page, st)
st, body, _ = emp.req('/wp-admin/admin.php?page=ews31-tasks')
check('an employee cannot open it', 'name="attach_max_mb"' not in body)
n_set = form_nonce(page, 'ews_tasks_settings_save')
on = {k: 1 for k in ['personal', 'checklist', 'comments', 'attachments', 'activity', 'teams', 'repeat', 'due_time', 'reminders', 'workload', 'home_card', 'drag', 'notify_comments', 'notify_status']}
_, qs, _ = adm.post('ews_tasks_settings_save', _wpnonce=n_set, attach_max_mb=99, home_count=0, done_limit=8, remind_default=7, team_mode='first', workload_who='managers', **{'attach_types[]': ['pdf', 'png', 'exe']}, **on)
cfg = php("echo wp_json_encode(get_option('ews_tasks_settings'));")
check('settings are saved and kept within their limits', '"attach_max_mb":20' in cfg and '"home_count":1' in cfg and '"remind_default":60' in cfg and '"team_mode":"first"' in cfg and '"attach_types":["pdf","png"]' in cfg, cfg)
_, qs, _ = adm.post('ews_tasks_settings_save', _wpnonce=n_set, **{k: v for k, v in on.items() if k != 'due_time'})
cfg = php("echo wp_json_encode(get_option('ews_tasks_settings'));")
check('without file types there are no files, and without a due time no reminders', '"attachments":0' in cfg and '"reminders":0' in cfg and '"due_time":0' in cfg, cfg)
_, qs, _ = adm.post('ews_tasks_settings_save', _wpnonce=n_set, attach_max_mb=1, home_count=5, done_limit=8, remind_default=60, team_mode='each', workload_who='managers', **{'attach_types[]': ['pdf', 'txt']}, **on)

# ---------------------------------------------------------------- a task with its checklist, comments and a file
st, page, _ = emp.req(PAGE)
n = form_nonce(page, 'ews_task_save')
emp.post('ews_task_save', _wpnonce=n, task_id=0, title='Prepare roster', priority='urgent', due_date=yesterday, due_time='09:00', remind_minutes=60)
t1 = task('Prepare roster')[0]
check('a task keeps its due time and reminder', t1['due_time'] == '09:00:00' and t1['remind_minutes'] == '60', t1)
st, page, _ = emp.req(PAGE + '&task=%s' % t1['id'])
check('a task opens in its own sheet', '<dialog class="wfo-sheet wfo-tk-detail" id="wfo-tk-detail"' in page and 'data-wfo-sheet-start' in page and 'Prepare roster' in page)
n_item = form_nonce(page, 'ews_task_item_add', task_id=t1['id'])
for step in ('Collect leave', 'Draft roster'):
    emp.post('ews_task_item_add', _wpnonce=n_item, task_id=t1['id'], title=step)
items = q("SELECT * FROM {p}ews_task_items WHERE task_id=%s ORDER BY position" % t1['id'])
check('steps are added in order', [i['title'] for i in items] == ['Collect leave', 'Draft roster'], items)
st, page, _ = emp.req(PAGE + '&task=%s' % t1['id'])
emp.post('ews_task_item_toggle', _wpnonce=form_nonce(page, 'ews_task_item_toggle', item_id=items[0]['id']), item_id=items[0]['id'])
check('a step is ticked, with who and when', q("SELECT done,done_by FROM {p}ews_task_items WHERE id=%s" % items[0]['id'])[0] == {'done': '1', 'done_by': str(I['uid'])})
st, page, _ = emp.req(PAGE)
check('the card shows the checklist progress', '1/2' in page)
st, page2, _ = emp2.req(PAGE + '&task=%s' % t1['id'])
check('someone else does not get the task', 'wfo-tk-detail' not in page2)
st, body, _ = emp2.req('/wp-admin/admin-post.php', {'action': 'ews_task_item_add', 'task_id': t1['id'], 'title': 'x', '_wpnonce': form_nonce(page2, 'ews_task_save') or 'x'})
check('...nor can add steps to it', len(q("SELECT id FROM {p}ews_task_items WHERE task_id=%s" % t1['id'])) == 2)

st, page, _ = emp.req(PAGE + '&task=%s' % t1['id'])
n_c = form_nonce(page, 'ews_task_comment', task_id=t1['id'])
check('the comment form takes a file', 'enctype="multipart/form-data"' in page and 'name="file"' in page and 'PDF, TXT · up to 1 MB' in page)
pdf = b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'
qs = multipart(emp, {'action': 'ews_task_comment', 'task_id': t1['id'], '_wpnonce': n_c, 'body': 'Draft attached'}, 'file', 'roster.pdf', pdf)
c = q("SELECT * FROM {p}ews_task_comments WHERE task_id=%s" % t1['id'])
check('a comment with a PDF is saved', len(c) == 1 and c[0]['file_name'] == 'roster.pdf' and c[0]['body'] == 'Draft attached', (qs, c))
fdir = php("$u=wp_upload_dir(); echo $u['basedir'].'/workforce-one-tasks';")
check('files are kept in a folder that denies direct access, under a random name', os.path.exists(fdir + '/.htaccess') and 'deny' in open(fdir + '/.htaccess').read().lower() and re.match(r'^[A-Za-z0-9]{32}\.pdf$', c[0]['file_key'] or ''), c[0]['file_key'])
qs = multipart(emp, {'action': 'ews_task_comment', 'task_id': t1['id'], '_wpnonce': n_c, 'body': 'evil'}, 'file', 'shell.php', b'<?php echo 1;')
check('a file type that is not allowed is refused', qs.get('task_error') == 'file_type' and len(q("SELECT id FROM {p}ews_task_comments WHERE task_id=%s" % t1['id'])) == 1, qs)
qs = multipart(emp, {'action': 'ews_task_comment', 'task_id': t1['id'], '_wpnonce': n_c, 'body': 'big'}, 'file', 'big.txt', b'a' * (1048576 + 10))
check('a file over the limit is refused', qs.get('task_error') == 'file_size', qs)
st, page, _ = emp.req(PAGE + '&task=%s' % t1['id'])
link = re.search(r'class="wfo-tk-file" href="([^"]+)"', page)
st, raw, h = get(emp, html.unescape(link.group(1))) if link else (0, b'', {})
check('the person on the task downloads the file', st == 200 and raw.startswith(b'%PDF') and 'roster.pdf' in h.get('Content-Disposition', ''), (st, raw[:20]))
st, raw, _ = get(emp2, html.unescape(link.group(1))) if link else (0, b'', {})
check('someone else cannot, even with the link', not raw.startswith(b'%PDF'), st)
check('the activity says who did what', 'created the task' in page and 'ticked Collect leave' in page and 'commented with roster.pdf' in page)

# ---------------------------------------------------------------- switching parts off
php("$c=get_option('ews_tasks_settings'); $c['comments']=0; $c['personal']=0; update_option('ews_tasks_settings',$c,false);")
st, page, _ = emp.req(PAGE + '&task=%s' % t1['id'])
check('with Comments off there is no comment form', 'name="body"' not in page and 'wfo-tk-detail' in page)
check('with Personal tasks off an employee has no New task', 'data-wfo-sheet="wfo-tk-sheet"' not in page)
_, qs, _ = emp.post('ews_task_save', _wpnonce=n, task_id=0, title='Sneaky')
check('...and cannot create one directly', qs.get('task_error') == 'personal' and not task('Sneaky'), qs)
php("$c=get_option('ews_tasks_settings'); $c['comments']=1; $c['personal']=1; update_option('ews_tasks_settings',$c,false);")

# ---------------------------------------------------------------- team tasks
st, page, _ = adm.req(PAGE)
check('a manager can send a task to a team', 'name="assign_kind" value="team"' in page and 'Front desk · 2 people' in page)
n_adm = form_nonce(page, 'ews_task_save')
_, qs, _ = adm.post('ews_task_save', _wpnonce=n_adm, task_id=0, title='Clean desks', assign_kind='team', team_id=TEAM, team_mode='each')
copies = task('Clean desks')
check('"one copy each" makes a task per member', qs.get('task_saved') == '2' and sorted(int(c['assigned_to']) for c in copies) == sorted([I['eid'], E2]) and len({c['batch'] for c in copies}) == 1, (qs, copies))
_, qs, _ = adm.post('ews_task_save', _wpnonce=n_adm, task_id=0, title='Answer the phone', assign_kind='team', team_id=TEAM, team_mode='first')
pool = task('Answer the phone')
check('"first one takes it" makes one task for the team', len(pool) == 1 and pool[0]['assigned_to'] is None and pool[0]['team_mode'] == 'first', pool)
check('the team is told', len(q("SELECT id FROM {p}ews_notifications WHERE entity='task' AND entity_id=%s" % pool[0]['id'])) == 2)
st, page, _ = emp.req(PAGE)
check('members see it with Take it', 'Answer the phone' in page and 'For Front desk' in page and form_nonce(page, 'ews_task_take', task_id=pool[0]['id']))
_, qs, _ = emp.post('ews_task_status_update', _wpnonce=form_nonce(page, 'ews_task_take', task_id=pool[0]['id']) or 'x', task_id=pool[0]['id'], status='in_progress')
check('it cannot be started before someone takes it', task('Answer the phone')[0]['status'] == 'todo')
_, qs, _ = emp.post('ews_task_take', _wpnonce=form_nonce(page, 'ews_task_take', task_id=pool[0]['id']), task_id=pool[0]['id'])
check('the first member takes it', int(task('Answer the phone')[0]['assigned_to'] or 0) == I['eid'], qs)
st, page2, _ = emp2.req(PAGE)
check('...and it leaves the other member\'s list', 'Answer the phone' not in re.findall(r'<h4 class="wfo-tk-title"><a [^>]*>([^<]*)</a>', page2))

# ---------------------------------------------------------------- repeating tasks and reminders
_, qs, _ = adm.post('ews_task_save', _wpnonce=n_adm, task_id=0, title='Water plants', assign_kind='person', assigned_to=I['eid'], repeat='weekly', **{'repeat_days[]': [0, 3]}, due_date=yesterday)
tpl = q("SELECT * FROM {p}ews_tasks WHERE title='Water plants' AND is_template=1")
first = task('Water plants')
check('a repeating task makes its first task and keeps the repeat hidden', len(tpl) == 1 and tpl[0]['repeat_rule'] == 'weekly:0,3' and len(first) == 1 and first[0]['repeat_of'] == tpl[0]['id'], (tpl, first))
php("$wpdb->update($p.'ews_tasks',['repeat_next'=>'%s'],['id'=>%s]);" % ((today - timedelta(days=9)).isoformat(), tpl[0]['id']))
php("do_action('ews_tasks_tick'); do_action('ews_tasks_tick');")
made = task('Water plants')
nxt = q("SELECT repeat_next FROM {p}ews_tasks WHERE id=%s" % tpl[0]['id'])[0]['repeat_next']
check('the run makes one task for the latest date that came (missed ones are not made up), once', len(made) == 2 and made[1]['due_date'] <= today.isoformat() and nxt > today.isoformat(), (made, nxt))
st, page, _ = emp.req(PAGE + '&task=%s' % made[1]['id'])
check('the task says it repeats; the manager can stop it', 'Repeats every week on Sun, Wed' in page)
st, apage, _ = adm.req(PAGE + '&task=%s' % made[1]['id'])
adm.post('ews_task_repeat_stop', _wpnonce=form_nonce(apage, 'ews_task_repeat_stop', template_id=tpl[0]['id']), template_id=tpl[0]['id'], task_id=made[1]['id'])
check('Stop repeating removes the repeat but keeps the tasks', not q("SELECT id FROM {p}ews_tasks WHERE id=%s" % tpl[0]['id']) and len(task('Water plants')) == 2)

now = php("echo current_time('Y-m-d H:i');").split()
soon = (php("echo date('H:i', current_time('timestamp')+1800);"))
_, qs, _ = emp.post('ews_task_save', _wpnonce=n, task_id=0, title='Call supplier', due_date=now[0], due_time=soon, remind_minutes=60)
php("do_action('ews_tasks_tick'); do_action('ews_tasks_tick');")
r = task('Call supplier')[0]
notes = q("SELECT title FROM {p}ews_notifications WHERE entity='task' AND entity_id=%s" % r['id'])
check('a reminder is sent once, an hour before the due time', r['reminded_at'] and [x['title'] for x in notes] == ['Task reminder'], (r['reminded_at'], notes))

# ---------------------------------------------------------------- Workload, Home, dragging
st, page, _ = adm.req(PAGE + '&task_tab=workload')
name1 = q("SELECT name FROM {p}ews_employees WHERE id=%s" % I['eid'])[0]['name']
check('managers have a Workload tab with each person', 'Team workload' in page and html.escape(name1) in page and 'Emp Two' in page and 'wfo-tk-load-row' in page)
st, page, _ = emp.req(PAGE + '&task_tab=workload')
check('employees do not', 'Team workload' not in page)
st, page, _ = emp.req('/app/?ews_view=dashboard')
check('Home shows My tasks today with the next step', 'My tasks today' in page and 'Prepare roster' in page and 'Overdue · since' in page and 'wfo-home-task-btn' in page)
php("$c=get_option('ews_tasks_settings'); $c['home_card']=0; update_option('ews_tasks_settings',$c,false);")
st, page, _ = emp.req('/app/?ews_view=dashboard')
check('...unless it is switched off', 'My tasks today' not in page)
st, page, _ = emp.req(PAGE)
check('cards can be dragged on a computer (with their own form data)', 'data-ews-drag' in page and 'draggable="true"' in page and 'data-ews-nonce="' in page)

# ---------------------------------------------------------------- deleting
st, page, _ = emp.req(PAGE)
emp.post('ews_task_delete', _wpnonce=form_nonce(page, 'ews_task_delete', task_id=t1['id']), task_id=t1['id'])
check('deleting a task removes its steps, comments, activity and files', not q("SELECT id FROM {p}ews_task_items WHERE task_id=%s" % t1['id']) and not q("SELECT id FROM {p}ews_task_comments WHERE task_id=%s" % t1['id']) and not os.path.exists(fdir + '/' + c[0]['file_key']))

# Leave nothing behind for the tests that run next (a task would show the Home card).
php("""delete_option('ews_tasks_settings');
foreach(['ews_tasks','ews_task_items','ews_task_comments','ews_task_activity'] as $t)$wpdb->query("DELETE FROM {$p}$t");
$wpdb->query("DELETE FROM {$p}ews_notifications WHERE entity='task'");
$wpdb->query("DELETE FROM {$p}ews_team_members WHERE team_id=%d"); $wpdb->query("DELETE FROM {$p}ews_teams WHERE id=%d");""" % (TEAM, TEAM))
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
