"""Behaviour tests for the employee app's Tasks page (3.31.69): the board by status, New task / Edit
in a sheet, Start / Done / Reopen / Back to To Do, Delete, overdue, the priority filter, My / Team
tasks and who may do what.

Usage: python3 tests/e2e_tasks_view.py <state dir>   (after tests/e2e_setup.php)
"""
import os, re, sys
from datetime import date, timedelta

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results  # noqa: E402

I = ids()
PAGE = '/app/?ews_view=tasks'
php("update_option('ews_feature_tasks',1,false); $wpdb->query(\"DELETE FROM {$p}ews_tasks\");")
yesterday = (date.today() - timedelta(days=1)).isoformat()
nextweek = (date.today() + timedelta(days=7)).isoformat()


def column(page, key):
    m = re.search(r'<section class="wfo-tk-col is-%s".*?</section>' % key, page, re.S)
    return re.findall(r'<h4 class="wfo-tk-title">([^<]*)</h4>', m.group(0)) if m else None


def task(title):
    rows = q("SELECT * FROM {p}ews_tasks WHERE title='%s'" % title)
    return rows[0] if rows else None


def nonce_for(page, action, task_id=None, status=None):
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'name="action" value="%s"' % action not in form:
            continue
        if task_id is not None and 'name="task_id" value="%s"' % task_id not in form:
            continue
        if status is not None and 'name="status" value="%s"' % status not in form:
            continue
        m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        if m:
            return m.group(1)
    return None


emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
st, page, _ = emp.req(PAGE)
check('the page opens with the board, its styles and scripts', st == 200 and column(page, 'todo') == [] and column(page, 'in_progress') == [] and column(page, 'completed') == []
      and 'app-requests.css' in page and 'tasks.js' in page and 'sheet.js' in page, st)
check('no styles written into the page and no emoji buttons', '<style>' not in page.split('class="ews-page wfo-rq wfo-tk"')[1] and '▶' not in page and '＋' not in page)
check('New task opens a sheet with the form', 'data-wfo-sheet="wfo-tk-sheet"' in page and '<dialog class="wfo-sheet" id="wfo-tk-sheet"' in page and 'name="title"' in page)
check('an employee has no My / Team tabs and no Assign To', 'wfo-tk-tabs' not in page and 'name="assigned_to"' not in page)

n = nonce_for(page, 'ews_task_save')
_, qs, _ = emp.post('ews_task_save', _wpnonce=n, task_id=0, title='  ', priority='high')
check('a task without a title is refused', qs.get('task_error') == 'title' and not q("SELECT id FROM {p}ews_tasks"), qs)
st, page, _ = emp.req(PAGE + '&task_error=title')
check('...and the page says why', 'Please enter a task title.' in page)

_, qs, _ = emp.post('ews_task_save', _wpnonce=n, task_id=0, title='Prepare roster', description='For October', priority='high', due_date=yesterday)
_, qs2, _ = emp.post('ews_task_save', _wpnonce=n, task_id=0, title='Order chairs', priority='bogus', due_date='next friday')
t1, t2 = task('Prepare roster'), task('Order chairs')
check('tasks are created as To Do', qs.get('task_saved') == '1' and t1 and t1['status'] == 'todo' and t1['priority'] == 'high' and t1['due_date'] == yesterday, (qs, t1))
check('an unknown priority becomes Normal and a bad date is dropped', t2 and t2['priority'] == 'normal' and t2['due_date'] is None, t2)
st, page, _ = emp.req(PAGE)
check('both are in To Do', sorted(column(page, 'todo') or []) == ['Order chairs', 'Prepare roster'], column(page, 'todo'))
check('a late task says Overdue and the page counts it', 'Overdue · ' in page and '1 task is overdue' in page)
check('each card shows its priority', 'wfo-tk-card pr-high' in page and 'wfo-tk-card pr-normal' in page)

_, qs, _ = emp.post('ews_task_status_update', _wpnonce=nonce_for(page, 'ews_task_status_update', t1['id'], 'in_progress'), task_id=t1['id'], status='completed')
check('To Do cannot jump straight to Done', qs.get('task_error') == 'transition' and task('Prepare roster')['status'] == 'todo', qs)
_, qs, _ = emp.post('ews_task_status_update', _wpnonce=nonce_for(page, 'ews_task_status_update', t1['id'], 'in_progress'), task_id=t1['id'], status='in_progress')
st, page, _ = emp.req(PAGE)
check('Start moves it to In Progress', task('Prepare roster')['status'] == 'in_progress' and column(page, 'in_progress') == ['Prepare roster'], column(page, 'in_progress'))
check('...where the button is Done and the menu offers Back to To Do', nonce_for(page, 'ews_task_status_update', t1['id'], 'completed') and nonce_for(page, 'ews_task_status_update', t1['id'], 'todo'))
emp.post('ews_task_status_update', _wpnonce=nonce_for(page, 'ews_task_status_update', t1['id'], 'completed'), task_id=t1['id'], status='completed')
st, page, _ = emp.req(PAGE)
done = task('Prepare roster')
check('Done moves it to Completed with the time', done['status'] == 'completed' and done['completed_at'] and column(page, 'completed') == ['Prepare roster'], done)
check('a finished task is no longer overdue', 'task is overdue' not in page)
emp.post('ews_task_status_update', _wpnonce=nonce_for(page, 'ews_task_status_update', t1['id'], 'in_progress'), task_id=t1['id'], status='in_progress')
check('Reopen brings it back to In Progress', task('Prepare roster')['status'] == 'in_progress' and task('Prepare roster')['completed_at'] is None)

st, page, _ = emp.req(PAGE + '&edit_task=%s' % t2['id'])
check('Edit opens its own sheet, filled in', '<dialog class="wfo-sheet" id="wfo-tk-edit"' in page and 'data-wfo-sheet-start' in page and 'value="Order chairs"' in page and 'name="task_id" value="%s"' % t2['id'] in page)
n_edit = re.search(r'id="wfo-tk-edit".*?name="_wpnonce" value="([^"]+)"', page, re.S).group(1)
_, qs, _ = emp.post('ews_task_save', _wpnonce=n_edit, task_id=t2['id'], title='Order 12 chairs', priority='low', due_date=nextweek)
t2 = task('Order 12 chairs')
check('Edit saves the changes and keeps the status', qs.get('task_updated') == '1' and t2 and t2['priority'] == 'low' and t2['due_date'] == nextweek and t2['status'] == 'todo', (qs, t2))

st, page, _ = emp.req(PAGE + '&task_priority=low')
check('the priority filter shows only that priority', column(page, 'todo') == ['Order 12 chairs'] and column(page, 'in_progress') == [], (column(page, 'todo'), column(page, 'in_progress')))

# A manager assigns a task to the employee.
st, page, _ = adm.req(PAGE)
check('a manager has My / Team tabs and Assign To', 'wfo-tk-tabs' in page and 'name="assigned_to"' in page)
_, qs, _ = adm.post('ews_task_save', _wpnonce=nonce_for(page, 'ews_task_save'), task_id=0, title='Audit badges', priority='urgent', assigned_to=I['eid'])
t3 = task('Audit badges')
check('the manager assigns it', t3 and int(t3['assigned_to']) == int(I['eid']), t3)
check('the employee is told', q("SELECT id FROM {p}ews_notifications WHERE user_id=%s AND entity='task'" % I['uid']))
_, qs, _ = adm.post('ews_task_save', _wpnonce=nonce_for(page, 'ews_task_save'), task_id=0, title='Admin only note')
st, page, _ = emp.req(PAGE)
check('the employee sees the assigned task but not the manager\'s own', 'Audit badges' in (column(page, 'todo') or []) and 'Admin only note' not in page)
card = re.search(r'<article class="wfo-tk-card pr-urgent".*?</article>', page, re.S).group(0)
check('...can start it, but not edit or delete it', 'value="in_progress"' in card and 'edit_task=' not in card and 'ews_task_delete' not in card)
st, page, _ = adm.req(PAGE + '&task_tab=team')
check('Team tasks shows who it is for', 'Audit badges' in page and 'wfo-tk-who' in page)

st, page, _ = emp.req(PAGE)
_, qs, _ = emp.post('ews_task_delete', _wpnonce=nonce_for(page, 'ews_task_delete', t2['id']), task_id=t2['id'])
check('the employee deletes an own task (after a confirmation in the page)', qs.get('task_deleted') == '1' and task('Order 12 chairs') is None and 'data-ews-task-delete="Delete this task?"' in page, qs)

php("update_option('ews_feature_tasks',0,false);")
st, page, _ = emp.req(PAGE)
check('with Tasks switched off the page says so', 'Tasks are currently disabled by your administrator.' in page and 'wfo-tk-board' not in page)
php("update_option('ews_feature_tasks',1,false);")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
