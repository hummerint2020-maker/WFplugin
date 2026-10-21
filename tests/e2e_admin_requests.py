"""Behaviour tests for the wp-admin request pages: "Requests Hub" and "Face Reset Requests".

Pins access control, which pending requests the hub lists, legacy vacation decisions, and Face
Reset decisions from both pages, with and without the approval workflow.
(Leave, overtime, early leave and swap decisions on the hub are covered by their module tests.)

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_admin_requests.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, urllib.error, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, d, ids, php, q, results, wp, HERE  # noqa: E402

HUB = '/wp-admin/admin.php?page=ews31-requests'
FACE_PAGE = '/wp-admin/admin.php?page=ews31-face-reset-requests'


def seed(face_mode='NONE', face_levels=0):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_leave_requests','ews_overtime_requests','ews_early_leave_requests','ews_schedule_swaps','ews_approval_requests','ews_approval_steps','ews_face_profiles','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_face_reset_requests');
        update_option('ews_feature_overtime',1,false);
        update_option('ews_working_days',[0,1,2,3,4,5,6],false);
        foreach(['vacation','overtime','face_reset'] as $k){
            $wf=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%%s",$k));
            $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf);
            $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);
        }
        $wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='face_reset'");
        if(%d){
            $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'%s','active'=>1],['id'=>$wf]);
            for($i=1;$i<=%d;$i++) $wpdb->insert($p.'ews_approval_workflow_steps',['workflow_id'=>$wf,'step_order'=>$i,'step_type'=>'APPROVAL','resolver_type'=>'SPECIFIC_USER','resolver_value'=>(string)get_user_by('login','admin')->ID,'required'=>1,'active'=>1]);
        }
        $c=$wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}ews_vacation_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,requested_days INT UNSIGNED NOT NULL DEFAULT 0,reason TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Pending',requested_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NULL,requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,PRIMARY KEY(id)) $c");
        $wpdb->query("DELETE FROM {$p}ews_vacation_requests");
    """ % (1 if face_levels else 0, face_mode, face_levels))


class Emp(Session):
    """Employee session that can also call the plugin's REST API (face enroll / reset)."""

    def rest(self, route, payload):
        st, page, _ = self.req('/app/?ews_view=time')
        m = re.search(r'data-api-base="([^"]+)" data-wp-nonce="([^"]+)"', page)
        if not m:
            return 0, 'no face module'
        api, nonce = m.groups()
        r = urllib.request.Request(api + route, data=json.dumps(payload).encode(), headers={'Content-Type': 'application/json', 'X-WP-Nonce': nonce})
        try:
            resp = self.op.open(r)
            return resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()


TEMPLATE = [0.01 * i for i in range(128)]


def enroll_and_request_reset(emp):
    emp.rest('face/enroll', {'template': TEMPLATE})
    st, body = emp.rest('face/reset-request', {})
    return st == 200 and 'requested' in body


def enrolled():
    return int(q("SELECT COUNT(*) c FROM {p}ews_face_profiles WHERE employee_id=%d" % ids()['eid'])[0]['c']) == 1


def reset_status():
    out = php("$r=get_option('ews_face_reset_requests',[]); echo $r[%d]['status']??'';" % ids()['eid'])
    return out.strip()


def notified(title):
    return int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE user_id=%d AND title='%s'" % (ids()['uid'], title))[0]['c']) >= 1


def audited(action):
    return int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='%s' AND entity_id=%d" % (action, ids()['eid']))[0]['c']) >= 1


def face_page_nonce(adm, action):
    st, page, _ = adm.req(FACE_PAGE)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form and 'name="employee_id" value="%d"' % ids()['eid'] in form:
            return re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
    return None


def hub_decide(adm, rtype, rid, decision):
    n = adm.admin_request_nonce(rtype, rid)
    _, qs, _ = adm.post('ews31_requests_decision', _wpnonce=n or 'missing', request_type=rtype, request_id=rid, decision=decision)
    return qs.get('request_notice')


def face_decide(adm, decision):
    action = 'ews31_face_reset_' + decision
    n = face_page_nonce(adm, action)
    _, qs, _ = adm.post(action, _wpnonce=n or 'missing', employee_id=ids()['eid'])
    return qs.get('face_reset_notice')


def legacy(start, end):
    php("$wpdb->insert($p.'ews_vacation_requests',['employee_id'=>%d,'start_date'=>'%s','end_date'=>'%s','requested_days'=>2,'reason'=>'legacy trip','status'=>'Pending','requested_by'=>%d,'requested_at'=>current_time('mysql')]);" % (ids()['eid'], start, end, ids()['uid']))
    return int(q("SELECT MAX(id) id FROM {p}ews_vacation_requests")[0]['id'])


def legacy_status(lid):
    return q("SELECT status FROM {p}ews_vacation_requests WHERE id=%d" % lid)[0]['status']


def sched(date):
    rows = q("SELECT status FROM {p}ews_schedule WHERE employee_id=%d AND work_date='%s'" % (ids()['eid'], date))
    return rows[0]['status'] if rows else None


# ---------------------------------------------------------------- listing + access
seed()
emp, adm = Emp('emp1', 'emp1pass'), Session('admin', 'admin')
eid = ids()['eid']

n = emp.nonce('ews_vacation_request_create')
annual = int(q("SELECT id FROM {p}ews_leave_types WHERE name='Annual Leave'")[0]['id'])
emp.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=annual, start_date=d(10), end_date=d(10), reason='family')
leave_id = int(q("SELECT MAX(id) id FROM {p}ews_leave_requests")[0]['id'] or 0)
n = emp.nonce('ews_overtime_request_create', view='overtime')
emp.post('ews_overtime_request_create', _wpnonce=n, overtime_date=d(1), start_time='18:00', end_time='19:30', reason='release')
ot_id = int(q("SELECT MAX(id) id FROM {p}ews_overtime_requests")[0]['id'] or 0)
php("$wpdb->delete($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s']); $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);" % (eid, d(3), eid, d(3)))
n = emp.nonce('ews_early_leave_create')
emp.post('ews_early_leave_create', _wpnonce=n, work_date=d(3), leave_minutes=60, reason='doctor')
el_id = int(q("SELECT MAX(id) id FROM {p}ews_early_leave_requests")[0]['id'] or 0)
l1 = legacy(d(20), d(21))
check('employee can request a face reset', enroll_and_request_reset(emp))
check('setup created leave, overtime and early leave requests', leave_id and ot_id and el_id, (leave_id, ot_id, el_id))

st, page, _ = adm.req(HUB)
check('hub opens for the administrator', st == 200 and 'Requests Hub' in page, st)
for rtype, rid, text in [('leave', leave_id, 'Vacation / Annual Leave'), ('overtime', ot_id, '1h 30m · release'), ('early_leave', el_id, '1h 0m · doctor'),
                         ('legacy_vacation', l1, 'legacy trip'), ('face_reset', eid, 'Face Reset')]:
    check('hub lists the pending %s request' % rtype, adm.admin_request_nonce(rtype, rid) and text in page, rtype)
check('hub shows the employee name', 'Emp One' in page)

st, page, _ = emp.req(HUB)
check('employee cannot open the hub', 'Requests Hub' not in page, st)
n = adm.admin_request_nonce('legacy_vacation', l1)
emp.post('ews31_requests_decision', _wpnonce=n, request_type='legacy_vacation', request_id=l1, decision='approve')
check('employee cannot decide from the hub', legacy_status(l1) == 'Pending')
st, _, _ = adm.post('ews31_requests_decision', _wpnonce='bad', request_type='legacy_vacation', request_id=l1, decision='approve')
check('a bad nonce is refused', st == 403 and legacy_status(l1) == 'Pending', st)
st, qs, _ = adm.post('ews31_requests_decision', _wpnonce=n, request_type='nonsense', request_id=l1, decision='approve')
check('an unknown request type is refused', 'request_notice' not in qs and legacy_status(l1) == 'Pending', (st, qs))
st, page, _ = emp.req(FACE_PAGE)
check('employee cannot open the Face Reset page', 'Face Reset Requests' not in page, st)

# ---------------------------------------------------------------- legacy vacation
check('hub approves a legacy vacation', hub_decide(adm, 'legacy_vacation', l1, 'approve') == 'approved' and legacy_status(l1) == 'Approved')
check('...and marks each day as Vacation', sched(d(20)) == 'Vacation' and sched(d(21)) == 'Vacation', (sched(d(20)), sched(d(21))))
check('...and tells the employee', notified('Vacation Approved'))
st, page, _ = adm.req(HUB + '&request_notice=approved')
check('the hub shows the success notice', 'Request approved successfully.' in page)
l2 = legacy(d(25), d(25))
check('hub rejects a legacy vacation', hub_decide(adm, 'legacy_vacation', l2, 'reject') == 'rejected' and legacy_status(l2) == 'Rejected' and sched(d(25)) is None)
php("$wpdb->update($p.'ews_vacation_requests',['status'=>'Pending'],['id'=>%d]);" % l2)
n2 = adm.admin_request_nonce('legacy_vacation', l2)
php("$wpdb->update($p.'ews_vacation_requests',['status'=>'Rejected'],['id'=>%d]);" % l2)
_, qs, _ = adm.post('ews31_requests_decision', _wpnonce=n2, request_type='legacy_vacation', request_id=l2, decision='approve')
check('deciding an already decided request reports an error', qs.get('request_notice') == 'error' and legacy_status(l2) == 'Rejected', qs)

# ---------------------------------------------------------------- face reset, no workflow
check('hub approves a face reset', hub_decide(adm, 'face_reset', eid, 'approve') == 'approved')
check('...the face template is removed', not enrolled())
check('...the request is closed as approved', reset_status() == 'approved', reset_status())
check('...the employee is told', notified('Face Reset Approved'))
check('...it is audited', audited('face_reset_approved'))
php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
check('a new reset can be requested', enroll_and_request_reset(emp))
check('hub rejects a face reset', hub_decide(adm, 'face_reset', eid, 'reject') == 'rejected')
check('...the face stays enrolled', enrolled())
check('...the request is closed as rejected', reset_status() == 'rejected', reset_status())
check('...the employee is told', notified('Face Reset Rejected'))
check('...it is audited', audited('face_reset_rejected'))

php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\"); $wpdb->query(\"DELETE FROM {$p}ews_audit_log\");")
emp.rest('face/reset-request', {})
check('Face Reset page lists the pending request', bool(face_page_nonce(adm, 'ews31_face_reset_approve')))
check('Face Reset page approves', face_decide(adm, 'approve') == 'approved' and not enrolled() and reset_status() == 'approved')
check('...the employee is told and it is audited', notified('Face Reset Approved') and audited('face_reset_approved'))
check('a new reset can be requested', enroll_and_request_reset(emp))
check('Face Reset page rejects', face_decide(adm, 'reject') == 'rejected' and enrolled() and reset_status() == 'rejected')
check('...the employee is told and it is audited', notified('Face Reset Rejected') and audited('face_reset_rejected'))
st, page, _ = adm.req(FACE_PAGE)
check('Face Reset page shows the history', 'Request History' in page and 'Rejected' in page)
_, qs, _ = adm.post('ews31_face_reset_approve', _wpnonce=face_page_nonce(adm, 'ews31_face_reset_approve') or 'x', employee_id=eid)
check('approving with no pending request is refused', enrolled(), qs)

# ---------------------------------------------------------------- face reset, two-level workflow
seed('LEVEL_2', 2)
emp, adm = Emp('emp1', 'emp1pass'), Session('admin', 'admin')
eid = ids()['eid']
check('[workflow] reset request starts the approval', enroll_and_request_reset(emp) and int(q("SELECT COUNT(*) c FROM {p}ews_approval_requests")[0]['c']) == 1)
st, page, _ = adm.req(HUB)
check('[workflow] hub shows the approval level', 'Level 1' in page)
check('[workflow] first hub approval only advances', hub_decide(adm, 'face_reset', eid, 'approve') == 'advanced' and enrolled() and reset_status() == 'pending')
check('[workflow] the next approver is notified', int(q("SELECT COUNT(*) c FROM {p}ews_notifications WHERE title='Face Reset Approval Required'")[0]['c']) >= 1)
check('[workflow] second hub approval completes it', hub_decide(adm, 'face_reset', eid, 'approve') == 'approved' and not enrolled() and reset_status() == 'approved')
check('[workflow] ...the employee is told and it is audited', notified('Face Reset Approved') and audited('face_reset_approved'))

check('[workflow] a new reset can be requested', enroll_and_request_reset(emp))
check('[workflow] Face Reset page: first approval only advances', face_decide(adm, 'approve') == 'advanced' and enrolled() and reset_status() == 'pending')
check('[workflow] Face Reset page: second approval completes it', face_decide(adm, 'approve') == 'approved' and not enrolled() and reset_status() == 'approved')
check('[workflow] a new reset can be requested', enroll_and_request_reset(emp))
check('[workflow] hub rejects at the first level', hub_decide(adm, 'face_reset', eid, 'reject') == 'rejected' and enrolled() and reset_status() == 'rejected')
check('[workflow] ...the employee is told', notified('Face Reset Rejected'))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
