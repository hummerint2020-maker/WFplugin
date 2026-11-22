"""Requests Hub reports (3.31.159, docs/tasks/hub-reports.md, mockups docs/mockups/hub-reports/).

The Requests Hub's "Reports" tab: every request of every type in every status, who decided it and when, filters, paging,
a CSV and Excel export, view only, administrators only. Off by default: with the switch off the Requests Hub is as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_hub_reports.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import csv, html, io, json, os, re, sys, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402

HUB = '/wp-admin/admin.php?page=ews31-requests'
REP = HUB + '&tab=reports'
TABLES = ['ews_leave_requests', 'ews_overtime_requests', 'ews_early_leave_requests', 'ews_schedule_swaps', 'ews_vacation_requests', 'ews_attendance_corrections',
          'ews_hr_letters', 'ews_approval_requests', 'ews_approval_steps', 'ews_notifications']

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
# Everything this suite changes is put back at the end (the suites after it start from the same state).
php("""file_put_contents(getenv('S').'/hub_reports_saved.ser',serialize($wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'",ARRAY_A)));""")

M = php("echo current_time('Y-m');").splitlines()[-1]
LAST = php("echo current_time('Y-m-t');").splitlines()[-1]
PREV = php("echo date('Y-m-t',strtotime(current_time('Y-m').'-01 -1 day'));").splitlines()[-1]
TODAY = php("echo current_time('Y-m-d');").splitlines()[-1]

seed = json.loads(php("""
    foreach(%s as $t) if($wpdb->get_var("SHOW TABLES LIKE '{$p}$t'")) $wpdb->query("DELETE FROM {$p}$t");
    delete_option('ews_face_reset_requests'); delete_option('ews_feature_hub_reports');
    $wpdb->query("DELETE FROM {$p}ews_audit_log WHERE action IN ('hub_reports_feature_update','requests_report_export')");
    $c=$wpdb->get_charset_collate();
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}ews_vacation_requests (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,requested_days INT UNSIGNED NOT NULL DEFAULT 0,reason TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Pending',requested_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NULL,requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,reviewed_at DATETIME NULL,PRIMARY KEY(id)) $c");
    $ids=json_decode(file_get_contents(getenv('S').'/ids.json'));$e1=(int)$ids->eid;$u1=(int)$ids->uid;
    $admin=(int)get_user_by('login','admin')->ID;
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE name IN ('HR Sales','HR Ops')");
    if($u=username_exists('hubset')){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($u);}
    $wpdb->insert($p.'ews_departments',['name'=>'HR Sales','code'=>'HRS','active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);$sales=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_departments',['name'=>'HR Ops','code'=>'HRO','active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);$ops=(int)$wpdb->insert_id;
    $wpdb->update($p.'ews_employees',['department_id'=>$sales],['id'=>$e1]);
    $wpdb->insert($p.'ews_employees',['name'=>'Karim Nagi','domain_name'=>'karim','email'=>'karim@example.com','active'=>1,'department_id'=>$ops]);$e2=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_employees',['name'=>'Laila Gone','domain_name'=>'laila','email'=>'laila@example.com','active'=>0,'department_id'=>$sales]);$e3=(int)$wpdb->insert_id;
    $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $M='%s';$LAST='%s';$PREV='%s';
    $leave=function($emp,$at,$status,$cs=null,$extra=[])use($wpdb,$p,$lt,$u1){
        $wpdb->insert($p.'ews_leave_requests',array_merge(['employee_id'=>$emp,'leave_type_id'=>$lt,'start_date'=>'2030-01-05','end_date'=>'2030-01-07','requested_days'=>3,'status'=>$status,'cancellation_status'=>$cs,'requested_by'=>$u1,'requested_at'=>$at],$extra));
        return (int)$wpdb->insert_id;
    };
    $wf=function($k)use($wpdb,$p){return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%%s",$k));};
    $out=[];
    // Leave: waiting, approved (directly), rejected at level 1 of 2 (engine, with a reason), withdrawn, cancelled after approval, shortened.
    $out['l_wait']=$leave($e1,"$M-10 10:00:00",'Pending');
    $out['l_ok']=$leave($e1,"$M-01 00:00:00",'Approved',null,['reviewed_by'=>$admin,'reviewed_at'=>"$M-01 09:00:00"]);        // the first second of the month
    $out['l_no']=$leave($e3,"$M-11 10:00:00",'Rejected',null,['reviewed_by'=>$admin,'reviewed_at'=>"$M-11 12:00:00"]);          // archived employee, in Sales
    $wpdb->insert($p.'ews_approval_requests',['workflow_id'=>$wf('vacation'),'entity_type'=>'leave','entity_id'=>$out['l_no'],'requester_employee_id'=>$e3,'status'=>'REJECTED','current_step'=>1,'created_at'=>"$M-11 10:00:00",'completed_at'=>"$M-11 12:00:00"]);$ar=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_approval_steps',['approval_request_id'=>$ar,'step_order'=>1,'approver_wp_user_id'=>$admin,'acted_by_wp_user_id'=>$admin,'resolver_type'=>'SPECIFIC_USER','status'=>'REJECTED','decision'=>'REJECTED','comment'=>'Half the department is off','acted_at'=>"$M-11 12:00:00"]);
    $wpdb->insert($p.'ews_approval_steps',['approval_request_id'=>$ar,'step_order'=>2,'resolver_type'=>'HR','status'=>'CANCELLED']);
    $out['l_wd']=$leave($e1,"$M-12 10:00:00",'Cancelled','Not Requested',['cancelled_by'=>$u1,'cancelled_at'=>"$M-12 11:00:00"]);
    $out['l_cx']=$leave($e1,"$M-13 10:00:00",'Cancelled','Approved',['reviewed_by'=>$admin,'reviewed_at'=>"$M-13 11:00:00",'cancelled_by'=>$admin,'cancelled_at'=>"$M-14 09:00:00"]);
    $out['l_short']=$leave($e1,"$M-14 10:00:00",'Approved','Approved',['reviewed_by'=>$admin,'reviewed_at'=>"$M-14 11:00:00",'cancel_from'=>'2030-01-06','end_date'=>'2030-01-05','cancelled_by'=>$admin,'cancelled_at'=>"$M-15 09:00:00"]);
    $out['l_ended']=$leave($e1,"$M-15 10:00:00",'Approved','Ended',['reviewed_by'=>$admin,'reviewed_at'=>"$M-15 11:00:00",'cancelled_by'=>$admin,'cancelled_at'=>"$M-16 09:00:00"]);
    $out['l_prev']=$leave($e1,"$PREV 23:59:59",'Approved');                                                                      // the month before: out
    $out['l_last']=$leave($e2,"$LAST 23:59:59",'Approved');                                                                      // the last second: in
    // A cancellation asked through the engine (its date is the approval request's).
    $wpdb->insert($p.'ews_approval_requests',['workflow_id'=>$wf('vacation'),'entity_type'=>'leave_cancellation','entity_id'=>$out['l_cx'],'requester_employee_id'=>$e1,'status'=>'APPROVED','current_step'=>1,'created_at'=>"$M-13 15:00:00",'completed_at'=>"$M-14 09:00:00"]);
    // Overtime and early leave.
    $wpdb->insert($p.'ews_overtime_requests',['employee_id'=>$e2,'overtime_date'=>"$M-09",'start_time'=>'18:00:00','end_time'=>'20:30:00','requested_minutes'=>150,'status'=>'Rejected','requested_by'=>$u1,'reviewed_by'=>$admin,'requested_at'=>"$M-09 21:05:00",'reviewed_at'=>"$M-10 08:40:00"]);$out['ot']=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_early_leave_requests',['employee_id'=>$e1,'work_date'=>"$M-07",'leave_minutes'=>60,'status'=>'Cancelled','requested_by'=>$u1,'requested_at'=>"$M-06 10:00:00"]);$out['el']=(int)$wpdb->insert_id;
    // Swaps: declined by the colleague, a day that passed, approved.
    $sw=function($req,$tgt,$day,$status,$at,$extra=[])use($wpdb,$p,$u1){$wpdb->insert($p.'ews_schedule_swaps',array_merge(['requester_employee_id'=>$req,'target_employee_id'=>$tgt,'work_date'=>$day,'requester_status'=>'Office','target_status'=>'WFH','status'=>$status,'requested_by'=>$u1,'created_at'=>$at],$extra));return (int)$wpdb->insert_id;};
    $out['sw_dec']=$sw($e1,$e2,'2030-01-10','Rejected',"$M-08 13:20:00",['responded_by'=>$u1,'responded_at'=>"$M-08 14:00:00"]);
    $out['sw_exp']=$sw($e2,$e1,'2020-01-01','Pending',"$M-08 13:21:00");
    $out['sw_peer']=$sw($e2,$e1,'2030-01-11','Pending',"$M-08 13:22:00");
    $out['sw_wd']=$sw($e1,$e2,'2030-01-12','Cancelled',"$M-08 13:23:00",['responded_by'=>$u1,'responded_at'=>"$M-08 13:30:00"]);
    $wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>$e1,'work_date'=>"$M-03",'type'=>'in','sign_in_at'=>"$M-03 09:05:00",'status'=>'approved','needs_hr'=>1,'requested_by'=>$u1,'requested_at'=>"$M-03 10:12:00",'manager_by'=>$admin,'manager_at'=>"$M-03 11:00:00",'decided_by'=>$admin,'decided_at'=>"$M-03 11:00:00"]);$out['cx_both']=(int)$wpdb->insert_id;
    // Corrections: waiting for HR, closed automatically.
    $wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>$e1,'work_date'=>"$M-05",'type'=>'in','sign_in_at'=>"$M-05 09:05:00",'status'=>'pending_hr','requested_by'=>$u1,'requested_at'=>"$M-05 10:12:00"]);$out['cx_hr']=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>$e1,'work_date'=>"$M-04",'type'=>'out','sign_out_at'=>"$M-04 17:30:00",'status'=>'rejected','requested_by'=>$u1,'requested_at'=>"$M-04 10:12:00",'decided_by'=>0,'decided_at'=>"$M-06 00:00:00",'decision_note'=>'=Closed: the payroll month ended']);$out['cx_auto']=(int)$wpdb->insert_id;
    // HR letters: issued, cancelled by HR.
    $wpdb->insert($p.'ews_hr_letters',['employee_id'=>$e2,'requested_by'=>$u1,'type_key'=>'salary','type_label'=>'شهادة مرتب','addressee'=>'Banque Misr','language'=>'ar','status'=>'issued','requested_at'=>"$M-04 11:00:00",'decided_at'=>"$M-04 12:15:00",'issued_at'=>"$M-04 12:16:00"]);$out['hl_iss']=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_hr_letters',['employee_id'=>$e1,'requested_by'=>$u1,'type_key'=>'work','type_label'=>'Employment certificate','addressee'=>'Embassy','language'=>'en','status'=>'cancelled','decision_note'=>'Wrong addressee','requested_at'=>"$M-03 11:00:00",'decided_at'=>"$M-03 12:00:00"]);$out['hl_cx']=(int)$wpdb->insert_id;
    // The old vacation table and a face reset.
    $wpdb->insert($p.'ews_vacation_requests',['employee_id'=>$e1,'start_date'=>'2030-02-01','end_date'=>'2030-02-02','requested_days'=>2,'status'=>'Approved','requested_by'=>$u1,'requested_at'=>"$M-02 10:00:00"]);$out['lv']=(int)$wpdb->insert_id;
    update_option('ews_face_reset_requests',[$e2=>['status'=>'rejected','requested_by'=>$u1,'requested_at'=>"$M-16 08:15:00",'handled_by'=>$admin,'handled_at'=>"$M-16 09:00:00"],
        $e1=>['status'=>'approved','requested_by'=>$u1,'requested_at'=>"$M-17 08:15:00",'handled_by'=>$u1,'handled_at'=>"$M-17 08:15:00"]],false);
    // The rejected one through its workflow, with a reason; the approved one closed by the workflow itself (no level).
    $wpdb->insert($p.'ews_approval_requests',['workflow_id'=>$wf('face_reset'),'entity_type'=>'face_reset_request','entity_id'=>$e2,'requester_employee_id'=>$e2,'status'=>'REJECTED','current_step'=>1,'created_at'=>"$M-16 08:15:00",'completed_at'=>"$M-16 09:00:00"]);$far=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_approval_steps',['approval_request_id'=>$far,'step_order'=>1,'approver_wp_user_id'=>$admin,'acted_by_wp_user_id'=>$admin,'resolver_type'=>'SPECIFIC_USER','status'=>'REJECTED','decision'=>'REJECTED','comment'=>'The current photo is fine','acted_at'=>"$M-16 09:00:00"]);
    $wpdb->insert($p.'ews_approval_requests',['workflow_id'=>$wf('face_reset'),'entity_type'=>'face_reset_request','entity_id'=>$e1,'requester_employee_id'=>$e1,'status'=>'APPROVED','current_step'=>null,'created_at'=>"$M-17 08:15:00",'completed_at'=>"$M-17 08:15:00"]);
    echo wp_json_encode($out+['e1'=>$e1,'e2'=>$e2,'e3'=>$e3,'sales'=>$sales,'ops'=>$ops]);
""" % (json.dumps(TABLES).replace('"', "'"), M, LAST, PREV)).splitlines()[-1])

adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
SEEDED = 25   # every row above in this month (the month before: one leave, out)


def rows(page):
    """[(type, status, cells)] of the Reports table."""
    out = []
    for m in re.finditer(r'<tr data-type="([a-z_]+)" data-status="([a-z]+)">(.*?)</tr>', page, re.S):
        cells = [re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', c)).strip() for c in re.findall(r'<td>(.*?)</td>', m.group(3), re.S)]
        out.append((m.group(1), m.group(2), cells))
    return out


def tiles(page):
    return {k: int(n.replace(',', '')) for k, n in re.findall(r'<li class="is-([a-z]+)[^"]*"><a [^>]*><b>([\d,]+)</b>', page)}


def find(rs, text):
    return next((r for r in rs if any(text in c for c in r[2])), None)


# ---------------------------------------------------------------- off by default
st, off, _ = adm.req(HUB)
check('off: the Requests Hub has no tabs', st == 200 and 'ews-hr-tabs' not in off and 'nav-tab' not in off.split('<h1>Requests Hub</h1>')[-1][:2000])
st, off2, _ = adm.req(REP)
check('off: ?tab=reports shows the same page, not the report', 'ews-hr-sum' not in off2 and ('No pending requests' in off2 or '<table class="widefat striped">' in off2))
check('off: the stylesheet is not loaded', 'admin-requests.css' not in off)
st, feat, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('off: the Features page has the switch, off', 'id="ews-hub-reports"' in feat and re.search(r'name="hub_reports_enabled"[^>]*checked', feat) is None)
check('off: nothing stored', php("var_export(get_option('ews_feature_hub_reports',null));").splitlines()[-1] == 'NULL')
pending_off = re.search(r'<table class="widefat striped">.*?</table>', off, re.S)

# ---------------------------------------------------------------- the switch
n = re.search(r'name="_wpnonce" value="([^"]+)"', feat)
features_nonce = n.group(1) if n else ''


def save_features(on):
    # Only this switch's own field: the others keep their state (a form without their "present" field leaves them).
    data = {'_wpnonce': features_nonce, 'hub_reports_present': '1'}
    if on:
        data['hub_reports_enabled'] = '1'
    return adm.post('ews31_features_save', **data)


save_features(True)
audit = lambda: q("SELECT details FROM {p}ews_audit_log WHERE action='hub_reports_feature_update' ORDER BY id")
check('the switch is saved and audited once', php("echo (int)get_option('ews_feature_hub_reports');").splitlines()[-1] == '1' and [a['details'] for a in audit()] == ['enabled'], audit())
save_features(True)
check('...saving again unchanged writes no audit row', len(audit()) == 1)

# ---------------------------------------------------------------- tab 1, on
st, on, _ = adm.req(HUB)
waiting = len(re.findall(r'name="action" value="ews31_requests_decision"', on)) // 2
check('on: two tabs, the first is current with the waiting count', 'ews-hr-tabs' in on and re.search(r'nav-tab nav-tab-active"[^>]*>Waiting for a decision <span class="ews-hr-count">(\d+)</span>', on) is not None
      and int(re.search(r'ews-hr-count">(\d+)<', on).group(1)) == waiting, waiting)
pending_on = re.search(r'<table class="widefat striped">.*?</table>', on, re.S)
strip = lambda m: re.sub(r'name="_wpnonce" value="[^"]+"', '', m.group(0)) if m else ''
check('on: the first tab\'s table is the same as with the switch off', strip(pending_on) == strip(pending_off))
check('on: the stylesheet is loaded', 'admin-requests.css' in on)

# ---------------------------------------------------------------- the Reports tab
st, rep, _ = adm.req(REP)
rs = rows(rep)
t = tiles(rep)
check('Reports: the current month by default', 'value="%s-01"' % M in rep and 'value="%s"' % LAST in rep, (M, LAST))
check('...every type and status of the month, the first and last second in, the month before out', t.get('all') == SEEDED, t)
check('...the totals by status', (t['approved'], t['rejected'], t['withdrawn'], t['cancelled'], t['waiting']) == (11, 5, 3, 3, 3), t)
check('...20 on a page, newest first', len(rs) == 20 and rs[0][2][4].startswith(LAST), [r[2][4] for r in rs[:2]])
check('...view only: no decision form', 'ews31_requests_decision' not in rep)
st, rep2, _ = adm.req(REP + '&paged=2')
rs2 = rows(rep2)
check('...page 2 has the rest, none twice', len(rs2) == SEEDED - 20 and not set(tuple(r[2]) for r in rs) & set(tuple(r[2]) for r in rs2), len(rs2))
allrows = rs + rs2

r = find(allrows, 'Half the department is off')
check('rejected at level 1 of 2: who "(level 1)", when, and the reason', r and r[1] == 'rejected' and '(level 1)' in r[2][5] and '%s-11 12:00' % M in r[2][5] and 'Laila Gone (archived)' == r[2][1], r)
r = next((x for x in allrows if x[0] == 'leave' and 'Cancelled at the employee' in x[2][6]), None)
check('a leave cancelled after approval: Cancelled, decided by its approver', r and r[1] == 'cancelled' and '%s-13 11:00' % M in r[2][5], r)
r = next((x for x in allrows if x[0] == 'leave' and 'Shortened' in x[2][6]), None)
check('a shortened leave stays Approved, "Shortened: now ends on"', r and r[1] == 'approved' and '2030-01-05' in r[2][6], r)
r = next((x for x in allrows if x[0] == 'leave' and x[1] == 'withdrawn'), None)
check('a withdrawn leave: by the employee', r and 'Withdrawn by the employee' in r[2][6] and 'Emp One' in r[2][5], r)
lc = [x for x in allrows if x[0] == 'leave_cancellation']
check('leave cancellations have their own rows (asked, ended, shortened)', len(lc) == 3 and {x[1] for x in lc} == {'approved'}, lc)
check('...one decided after the leave ended says nothing changed', any('nothing changed' in x[2][6] for x in lc))
check('...one asked through the engine takes the approval\'s date; the others say the date was not recorded',
      any(x[2][4].startswith('%s-13 15:00' % M) and 'not recorded' not in x[2][6] for x in lc) and sum('Date not recorded' in x[2][6] for x in lc) == 2, lc)
r = find(allrows, 'Declined by the colleague')
check('a swap declined by the colleague: Rejected, by the colleague', r and r[1] == 'rejected' and r[0] == 'shift_swap', r)
r = find(allrows, 'The day passed')
check('a swap whose day passed before an answer: Cancelled, "The day passed"', r and r[1] == 'cancelled', r)
r = find(allrows, 'Waiting for the colleague')
check('a swap still waiting for the colleague: Waiting', r and r[1] == 'waiting', r)
r = find(allrows, 'Second level (HR)')
check('a correction waiting for HR', r and r[1] == 'waiting' and r[0] == 'correction', r)
r = find(allrows, 'Closed: the payroll month ended')
check('a correction closed by the system: "Automatically"', r and r[1] == 'rejected' and 'Automatically' in r[2][5], r)
r = find(allrows, 'Issued')
check('an issued HR letter: Approved, "Issued"', r and r[0] == 'hr_letter' and r[1] == 'approved', r)
r = find(allrows, 'Cancelled by HR: Wrong addressee')
check('an HR letter cancelled by HR: Cancelled with the reason', r and r[1] == 'cancelled', r)
r = next((x for x in allrows if x[0] == 'legacy_vacation'), None)
check('the old vacation table is listed as "Leave (old)"', r and r[2][0] == 'Leave (old)', r)
r = next((x for x in allrows if x[0] == 'face_reset'), None)
check('a face reset (from the option) is in the list, in order', r and r[2][4].startswith('%s-17 08:15' % M), r)
faces = [x for x in allrows if x[0] == 'face_reset']
check('a face reset rejected through its workflow shows the reason', any(x[1] == 'rejected' and 'The current photo is fine' in x[2][6] for x in faces), faces)
check('a face reset closed by its workflow itself: "Automatically", not the employee', any(x[1] == 'approved' and 'Automatically' in x[2][5] for x in faces), faces)
r = next((x for x in allrows if x[0] == 'shift_swap' and x[1] == 'withdrawn'), None)
check('a withdrawn swap: by the requester', r and 'Emp One' in r[2][5] and 'Withdrawn by the employee' in r[2][6], r)
r = next((x for x in allrows if x[0] == 'correction' and x[1] == 'approved'), None)
check('a correction HR decided at both levels at once: "(HR)"', r and '(HR)' in r[2][5], r)
r = next((x for x in allrows if x[0] == 'early_leave'), None)
check('withdrawn early leave', r and r[1] == 'withdrawn', r)

# ---------------------------------------------------------------- filters
st, p, _ = adm.req(REP + '&type=leave')
check('Type "Leave" lists leave and the old vacation table only', {x[0] for x in rows(p)} == {'leave', 'legacy_vacation'} and tiles(p)['all'] == 9, tiles(p))
st, p, _ = adm.req(REP + '&status=rejected')
check('Status "Rejected": one tile current, only rejected rows', {x[1] for x in rows(p)} == {'rejected'} and len(rows(p)) == 5 and 'is-rejected is-current' in p)
st, p, _ = adm.req(REP + '&who=emp:%d' % seed['e2'])
check('an employee: their requests and the swaps they were asked for', {x[0] for x in rows(p)} >= {'shift_swap', 'overtime', 'face_reset', 'hr_letter'}
      and any(x[0] == 'shift_swap' and 'Karim Nagi' not in x[2][1] for x in rows(p)), [x[0] for x in rows(p)])
st, p, _ = adm.req(REP + '&who=dept:%d&type=leave&status=rejected' % seed['sales'])
check('a department (an archived employee included), Leave, Rejected: the reasons', [x[2][1] for x in rows(p)] == ['Laila Gone (archived)'] and 'Half the department is off' in p)
st, p, _ = adm.req(REP + '&who=dept:%d&type=face_reset' % seed['ops'])
check('...a face reset follows its employee\'s department', len(rows(p)) == 1)
st, p, _ = adm.req(REP + '&from=%s&to=%s-01' % (LAST, M))
check('From after To is swapped', tiles(p)['all'] == SEEDED and 'value="%s-01"' % M in p)
st, p, _ = adm.req(REP + '&from=2001-01-01&to=2001-01-31')
check('an empty period says so', 'No requests in this period' in p)

# ---------------------------------------------------------------- who may see it
st, p, _ = emp.req(REP)
check('an employee cannot open it', 'ews-hr-sum' not in p and st in (200, 403))
export = {f: html.unescape(u).replace('http://127.0.0.1:8080', '') for u, f in re.findall(r'href="([^"]*action=ews31_requests_export[^"]*format=(csv|xlsx)[^"]*)"', rep)}
st, p, _ = emp.req(export['csv'])
check('...nor export it', 'Request,Employee' not in p)
st, p, _ = adm.req(re.sub(r'_wpnonce=[^&]+', '_wpnonce=bad', export['csv']))
check('an export without a valid nonce is refused', 'Request,Employee' not in p)

php("$u=username_exists('hubset')?:wp_create_user('hubset','hubsetpass','hubset@example.com');$w=new WP_User($u);$w->set_role('subscriber');$w->add_cap('ews_manage_settings');$w->add_cap('ews_view_reports');")
hs = Session('hubset', 'hubsetpass')
st, p, _ = hs.req(REP)
check('a user who manages settings but is not an administrator cannot open it', 'ews-hr-sum' not in p)
st, p, _ = hs.req(export['csv'])
check('...nor export it', 'Request,Employee' not in p)

# ---------------------------------------------------------------- export
st, body, h = adm.req(export['csv'])
check('CSV: a file with a BOM', body.startswith('﻿') and 'text/csv' in h.get('Content-Type', ''), h.get('Content-Type'))
lines = list(csv.reader(io.StringIO(body.lstrip('﻿'))))
check('...every page: one line per request, in the screen\'s order', len(lines) - 1 == SEEDED and [l[0] for l in lines[1:21]] == [x[2][0] for x in rs], len(lines))
check('...with a Department column', lines[0][2] == 'Department' and 'HR Sales' in {l[2] for l in lines[1:]})
check('...a cell starting with "=" is kept as text, Arabic survives', any(l[8] == "'=Closed: the payroll month ended" for l in lines) and any('شهادة مرتب' in l[3] for l in lines))
# Batches of 3 (a test mu-plugin): the face reset falls between two batches; the file is the same.
php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-hub-batch.php','<?php add_filter(\\'ews_hub_export_batch\\',function(){return 3;});');")
st, small, _ = adm.req(export['csv'])
php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-hub-batch.php');")
check('...the same file in batches of 3, the face reset between two of them', small == body, (len(small), len(body)))
xl = adm.op.open('http://127.0.0.1:8080' + export['xlsx']).read()
try:
    z = zipfile.ZipFile(io.BytesIO(xl))
    sheet = z.read('xl/worksheets/sheet1.xml').decode()
    kpi = re.search(r'<row r="7".*?</row>', sheet).group(0)
    ok = ('Half the department is off' in sheet and 'Definitions' not in z.read('xl/workbook.xml').decode() and sheet.count('<row ') == 9 + SEEDED
          and re.findall(r'<v>(\d+)</v>', kpi) == [str(SEEDED), '11', '5', '3', '3', '3'])
except Exception as e:
    ok, sheet = False, str(e)
check('Excel: the Report Center\'s workbook, every row, the totals as cards, no Definitions sheet', ok, sheet[:300])
au = q("SELECT details FROM {p}ews_audit_log WHERE action='requests_report_export' ORDER BY id")
check('each export is in the Audit Log with its filters and rows', len(au) >= 2 and 'CSV' in au[0]['details'] and 'rows: %d' % SEEDED in au[0]['details'], au)

# ---------------------------------------------------------------- a decision on the first tab
nonce = adm.admin_request_nonce('leave', seed['l_wait'])
st, qs, _ = adm.post('ews31_requests_decision', request_type='leave', request_id=str(seed['l_wait']), decision='reject', approval_step='0', _wpnonce=nonce or '')
check('a decision on the first tab still comes back to it', 'page=ews31-requests' in adm.last_location and 'tab=reports' not in adm.last_location and qs.get('request_notice') == 'rejected', adm.last_location)

# ---------------------------------------------------------------- off again
save_features(False)
st, p, _ = adm.req(REP)
check('switched off again: no tabs, audited "disabled"', 'ews-hr-tabs' not in p and [a['details'] for a in audit()] == ['enabled', 'disabled'])
st, p, _ = adm.req(export['csv'])
check('...the export (a valid link from before) is refused', 'switched off' in p and 'Request,Employee' not in p, p[:200])

php("""$s=unserialize(file_get_contents(getenv('S').'/hub_reports_saved.ser'));$keep=[];
    foreach($s as $o){$keep[]=$o['option_name'];$wpdb->replace($wpdb->options,$o);}
    foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ews\\\\_%' AND option_name NOT LIKE '%transient%'") as $n) if(!in_array($n,$keep,true)) $wpdb->delete($wpdb->options,['option_name'=>$n]);
    wp_cache_flush();
    foreach(""" + json.dumps(TABLES).replace('"', "'") + """ as $t) if($wpdb->get_var("SHOW TABLES LIKE '{$p}$t'")) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE name IN ('HR Sales','HR Ops')");""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
