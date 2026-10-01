"""Behaviour tests for Leave requests, approvals, balances and cancellations (HTTP, real site).

Pins: validation errors, working-day counting, pending/used balance movements, schedule
snapshot + restore, rejection, cancellation rules, non-deducting leave types, access control,
and the same flows with the one-level approval workflow switched on.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, http.cookiejar, json, os, re, shlex, subprocess, sys, urllib.parse, urllib.request

B = 'http://127.0.0.1:8080'
S = sys.argv[1]
WP = shlex.split(os.environ.get('WP_CLI', 'wp'))
HERE = os.path.dirname(os.path.abspath(__file__))
results = []


def wp(*args):
    r = subprocess.run(WP + list(args), capture_output=True, text=True, env={**os.environ, 'S': S})
    if r.returncode:
        raise RuntimeError('wp ' + ' '.join(args) + '\n' + r.stderr[-2000:])
    return r.stdout.strip()


def php(code):
    return wp('eval', 'global $wpdb; $p=$wpdb->prefix; ' + code)


def q(sql):
    """Run a SELECT and return rows as dicts ({p} = table prefix)."""
    out = php('echo wp_json_encode($wpdb->get_results("' + sql.replace('{p}', '{$p}').replace('"', '\\"') + '",ARRAY_A));')
    return json.loads(out or '[]')


def check(name, cond, extra=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  | ' + str(extra)[:400]))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Session:
    def __init__(self, user, pwd):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
        self.req('/wp-login.php')
        self.req('/wp-login.php', {'log': user, 'pwd': pwd, 'testcookie': '1'})

    def req(self, path, data=None):
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
        try:
            r = self.op.open(urllib.request.Request(B + path, data=data))
            return r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)

    def nonce(self, action):
        st, page, _ = self.req('/app/?ews_view=vacation')
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'name="action" value="%s"' % action in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
                if m:
                    return m.group(1)
        return None

    def admin_request_nonce(self, rtype, rid):
        """Nonce of a decision form on the wp-admin "Requests" page."""
        st, page, _ = self.req('/wp-admin/admin.php?page=ews31-requests')
        for form in re.findall(r'<form.*?</form>', page, re.S):
            if 'value="ews31_requests_decision"' in form and 'name="request_type" value="%s"' % rtype in form and 'name="request_id" value="%d"' % rid in form:
                m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
                if m:
                    return m.group(1)
        return None

    def post(self, action, **data):
        st, body, h = self.req('/wp-admin/admin-post.php', {'action': action, **data})
        loc = h.get('Location', '')
        qs = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
        return st, {k: v[0] for k, v in qs.items()}, body


today = datetime.date.today()


def d(days):
    return (today + datetime.timedelta(days=days)).isoformat()


def next_weekday(wp_dow, after=3):
    """Next date (at least `after` days ahead) whose WordPress weekday (0=Sunday) is wp_dow."""
    x = today + datetime.timedelta(days=after)
    while (x.isoweekday() % 7) != wp_dow:
        x += datetime.timedelta(days=1)
    return x


def ids():
    return json.load(open(os.path.join(S, 'ids.json')))


def seed(approval=False, entitlement=21):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name<>'Annual Leave'");
        $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=%d WHERE name='Annual Leave'");
        if(!$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Mission'")) $wpdb->insert($p.'ews_leave_types',['name'=>'Mission','active'=>1,'deduct_balance'=>0,'annual_entitlement'=>0]);
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        $wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='vacation'");
        $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf);
        $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'%s','active'=>%d],['id'=>$wf]);
        if(%d) $wpdb->insert($p.'ews_approval_workflow_steps',['workflow_id'=>$wf,'step_order'=>1,'step_type'=>'APPROVAL','resolver_type'=>'SPECIFIC_USER','resolver_value'=>(string)get_user_by('login','admin')->ID,'required'=>1,'active'=>1]);
    """ % (entitlement, 'LEVEL_1' if approval else 'NONE', 1 if approval else 0, 1 if approval else 0))


def type_id(name):
    return int(q("SELECT id FROM {p}ews_leave_types WHERE name='%s'" % name)[0]['id'])


def balance(year=None):
    year = year or today.year
    rows = q("SELECT entitlement,used,pending FROM {p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d" % (ids()['eid'], type_id('Annual Leave'), year))
    return {k: float(v) for k, v in rows[0].items()} if rows else None


def leave(lid):
    rows = q("SELECT status,cancellation_status,requested_days FROM {p}ews_leave_requests WHERE id=%d" % lid)
    return rows[0] if rows else None


def last_leave_id():
    rows = q("SELECT MAX(id) id FROM {p}ews_leave_requests")
    return int(rows[0]['id'] or 0)


def schedule(date):
    rows = q("SELECT status,note FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids()['eid'], date))
    return rows[0] if rows else None


def run(mode_approval):
    tag = '[approval] ' if mode_approval else ''
    seed(approval=mode_approval)
    emp, adm = Session('emp1', 'emp1pass'), Session('admin', 'admin')
    n_create = emp.nonce('ews_vacation_request_create')
    check(tag + 'leave form is shown to the employee', bool(n_create))
    annual = type_id('Annual Leave')

    def create(start, end, t=annual):
        return emp.post('ews_vacation_request_create', _wpnonce=n_create, leave_type_id=t, start_date=start, end_date=end, reason='test')

    # validation
    _, qs, _ = create(today.isoformat(), d(1))
    check(tag + 'leave starting today is rejected', qs.get('leave_error') == 'date', qs)
    _, qs, _ = create(d(5), d(4))
    check(tag + 'end before start is rejected', qs.get('leave_error') == 'date', qs)
    php("update_option('ews_working_days',[0,1,2,3,4],false);")      # Sunday..Thursday
    fri = next_weekday(5)
    _, qs, _ = create(fri.isoformat(), (fri + datetime.timedelta(days=1)).isoformat())
    check(tag + 'range with only weekend days is rejected', qs.get('leave_error') == 'no_working_days', qs)
    _, qs, _ = create(fri.isoformat(), (fri + datetime.timedelta(days=3)).isoformat())   # Fri..Mon -> Sun, Mon
    check(tag + 'only working days are counted', qs.get('leave_sent') == '2', qs)
    php("update_option('ews_working_days',[0,1,2,3,4,5,6],false);")
    php("$wpdb->query(\"DELETE FROM {$p}ews_leave_requests\"); $wpdb->query(\"UPDATE {$p}ews_leave_balances SET pending=0,used=0\");")
    _, qs, _ = create(d(3), d(30))
    check(tag + 'more days than the balance is rejected', qs.get('leave_error') == 'balance', qs)

    # create -> pending
    php("$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'WFH','note'=>'planned']);" % (ids()['eid'], d(4)))
    _, qs, _ = create(d(3), d(5))
    lid = last_leave_id()
    check(tag + 'valid request is submitted for 3 days', qs.get('leave_sent') == '3' and leave(lid)['status'] == 'Pending', (qs, leave(lid)))
    check(tag + '...and reserves 3 pending days', balance() == {'entitlement': 21.0, 'used': 0.0, 'pending': 3.0}, balance())
    _, qs, _ = create(d(5), d(6))
    check(tag + 'overlapping request is rejected', qs.get('leave_error') == 'overlap', qs)

    # employees cannot decide
    n_emp_respond = emp.nonce('ews_vacation_request_respond') or adm.nonce('ews_vacation_request_respond')
    st, qs, body = emp.post('ews_vacation_request_respond', _wpnonce=n_emp_respond, request_id=lid, decision='approve')
    check(tag + 'employee cannot approve a leave', leave(lid)['status'] == 'Pending', (st, qs, body[:200]))

    # approve
    n_respond = adm.nonce('ews_vacation_request_respond')
    check(tag + 'manager sees the approval form', bool(n_respond))
    _, qs, _ = adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid, decision='approve')
    check(tag + 'manager approves', leave(lid)['status'] == 'Approved', (qs, leave(lid)))
    check(tag + '...pending days become used', balance() == {'entitlement': 21.0, 'used': 3.0, 'pending': 0.0}, balance())
    check(tag + '...schedule shows Vacation on each day', all((schedule(d(i)) or {}).get('status') == 'Vacation' for i in (3, 4, 5)), [schedule(d(i)) for i in (3, 4, 5)])
    snaps = q("SELECT work_date,had_schedule,status FROM {p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date" % lid)
    check(tag + '...previous schedule is snapshotted', [s['had_schedule'] for s in snaps] == ['0', '1', '0'] and snaps[1]['status'] == 'WFH', snaps)
    _, qs, _ = adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid, decision='reject')
    check(tag + 'deciding twice changes nothing', leave(lid)['status'] == 'Approved' and balance()['used'] == 3.0, (leave(lid), balance()))

    # reject another
    _, qs, _ = create(d(10), d(11))
    lid2 = last_leave_id()
    check(tag + 'second request reserves 2 more days', balance()['pending'] == 2.0, balance())
    _, qs, _ = adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid2, decision='reject')
    check(tag + 'manager rejects: status Rejected, pending released', leave(lid2)['status'] == 'Rejected' and balance()['pending'] == 0.0, (leave(lid2), balance()))
    check(tag + '...rejected leave does not touch the schedule', schedule(d(10)) is None, schedule(d(10)))

    # cancellation of the approved leave, then approve it
    n_cancel = emp.nonce('ews_leave_cancel')
    check(tag + 'cancel form is shown for an approved future leave', bool(n_cancel))
    emp.post('ews_leave_cancel', _wpnonce=n_cancel, request_id=lid)
    check(tag + 'cancellation request is pending', leave(lid)['cancellation_status'] == 'Pending', leave(lid))
    st, qs, body = emp.post('ews_leave_cancel', _wpnonce=n_cancel, request_id=lid)
    check(tag + 'a second cancellation request is refused', 'already pending' in body, (st, body[:200]))
    n_cresp = adm.nonce('ews_leave_cancel_respond')
    check(tag + 'manager sees the cancellation form', bool(n_cresp))
    adm.post('ews_leave_cancel_respond', _wpnonce=n_cresp, request_id=lid, decision='approve')
    check(tag + 'cancellation approved: status Cancelled', leave(lid)['status'] == 'Cancelled' and leave(lid)['cancellation_status'] == 'Approved', leave(lid))
    check(tag + '...used days are returned', balance() == {'entitlement': 21.0, 'used': 0.0, 'pending': 0.0}, balance())
    check(tag + '...schedule restored (WFH kept, added days removed)',
          schedule(d(4)) == {'status': 'WFH', 'note': 'planned'} and schedule(d(3)) is None and schedule(d(5)) is None,
          [schedule(d(i)) for i in (3, 4, 5)])

    # cancellation rejected
    _, qs, _ = create(d(20), d(20))
    lid3 = last_leave_id()
    adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid3, decision='approve')
    emp.post('ews_leave_cancel', _wpnonce=emp.nonce('ews_leave_cancel'), request_id=lid3)
    adm.post('ews_leave_cancel_respond', _wpnonce=adm.nonce('ews_leave_cancel_respond'), request_id=lid3, decision='reject')
    check(tag + 'cancellation rejected: leave stays approved', leave(lid3)['status'] == 'Approved' and leave(lid3)['cancellation_status'] == 'Rejected', leave(lid3))
    st, qs, body = emp.post('ews_leave_cancel', _wpnonce=n_cancel, request_id=lid3)
    check(tag + 'a rejected cancellation cannot be requested again', 'already rejected' in body, body[:200])
    check(tag + '...and the day is still Vacation with 1 day used', (schedule(d(20)) or {}).get('status') == 'Vacation' and balance()['used'] == 1.0, (schedule(d(20)), balance()))

    # non-deducting type
    before = balance()
    _, qs, _ = create(d(25), d(26), t=type_id('Mission'))
    lid4 = last_leave_id()
    adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid4, decision='approve')
    check(tag + 'non-deducting leave is approved without touching the balance', leave(lid4)['status'] == 'Approved' and balance() == before, (leave(lid4), balance(), before))

    # leave is charged to the balance of the year it falls in
    ny = today.year + 1
    this_year = balance()
    _, qs, _ = create('%d-01-10' % ny, '%d-01-11' % ny)
    lid5 = last_leave_id()
    check(tag + 'next-year leave reserves days on next year\'s balance', qs.get('leave_sent') == '2' and (balance(ny) or {}).get('pending') == 2.0, (qs, balance(ny)))
    check(tag + '...and leaves this year\'s balance untouched', balance() == this_year, (balance(), this_year))
    adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid5, decision='approve')
    check(tag + '...approval moves them to next year\'s used days', balance(ny) == {'entitlement': 21.0, 'used': 2.0, 'pending': 0.0} and balance() == this_year, (balance(ny), balance()))
    emp.post('ews_leave_cancel', _wpnonce=emp.nonce('ews_leave_cancel'), request_id=lid5)
    adm.post('ews_leave_cancel_respond', _wpnonce=adm.nonce('ews_leave_cancel_respond'), request_id=lid5, decision='approve')
    check(tag + '...cancellation returns them to next year\'s balance', balance(ny) == {'entitlement': 21.0, 'used': 0.0, 'pending': 0.0} and balance() == this_year, (balance(ny), balance()))
    _, qs, _ = create('%d-12-30' % ny, '%d-01-02' % (ny + 1))
    check(tag + 'a leave spanning two years is refused', qs.get('leave_error') == 'cross_year', qs)

    # the wp-admin "Requests" page uses the same rules as the employee app
    this_year, next_year = balance(), balance(ny)
    _, qs, _ = create('%d-03-10' % ny, '%d-03-11' % ny)
    lid7 = last_leave_id()
    n = adm.admin_request_nonce('leave', lid7)
    check(tag + 'admin Requests page lists the leave', bool(n))
    adm.post('ews31_requests_decision', _wpnonce=n, request_type='leave', request_id=lid7, decision='approve')
    check(tag + 'admin Requests page approval charges next year\'s balance',
          leave(lid7)['status'] == 'Approved' and balance(ny)['used'] == next_year['used'] + 2 and balance(ny)['pending'] == next_year['pending'] and balance() == this_year,
          (leave(lid7), balance(ny), next_year, balance(), this_year))
    emp.post('ews_leave_cancel', _wpnonce=emp.nonce('ews_leave_cancel'), request_id=lid7)
    n = adm.admin_request_nonce('leave_cancellation', lid7)
    adm.post('ews31_requests_decision', _wpnonce=n, request_type='leave_cancellation', request_id=lid7, decision='approve')
    check(tag + 'admin Requests page cancellation returns next year\'s days and restores the schedule',
          leave(lid7)['status'] == 'Cancelled' and balance(ny) == next_year and balance() == this_year and schedule('%d-03-10' % ny) is None,
          (leave(lid7), balance(ny), next_year, schedule('%d-03-10' % ny)))

    # requests created before this change were charged to the year they were submitted in
    if not mode_approval:
        php("""$b=$wpdb->get_row("SELECT id FROM {$p}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d");
               $wpdb->insert($p.'ews_leave_requests',['employee_id'=>%d,'leave_type_id'=>%d,'start_date'=>'%d-02-10','end_date'=>'%d-02-10','requested_days'=>1,'reason'=>'legacy','status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
               $wpdb->query("UPDATE {$p}ews_leave_balances SET pending=pending+1 WHERE id=".(int)$b->id);"""
            % (ids()['eid'], annual, today.year, ids()['eid'], annual, ny, ny))
        lid6 = last_leave_id()
        before_this, before_next = balance(), balance(ny)
        adm.post('ews_vacation_request_respond', _wpnonce=n_respond, request_id=lid6, decision='approve')
        check(tag + 'legacy request is settled on the balance it was charged to',
              leave(lid6)['status'] == 'Approved' and balance()['pending'] == before_this['pending'] - 1 and balance()['used'] == before_this['used'] + 1 and balance(ny) == before_next,
              (balance(), before_this, balance(ny), before_next))


run(False)
run(True)
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
