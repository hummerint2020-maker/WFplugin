"""Leave type on the Attendance page and the cards it shows (3.31.152, docs/tasks/leave-type-attendance.md,
mockups docs/mockups/leave-type/).

With Leave type on, a day of approved leave shows its type (the name in Leave Types) instead of "Leave", and a Leave days
card counts the leave days of the week with the types under it (company holidays left out; a day set to leave by hand, or
a request without a type, is "Other leave"). With Attendance cards on, the Leave days card opens its list. The
administrator chooses which cards the page shows. Off (the default) the page is as before.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_leave_type.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, q, results, wp, HERE  # noqa: E402


def seed():
    """Last week (Sun-Thu working days, a company holiday on Thursday). Kareem: Annual Leave Sun-Mon; Amr: Sick Leave Sun;
    Wael: Casual Leave Tue; Hala: Annual Leave Wed-Thu (Thu is the holiday); Sherif: Vacation set by hand Wed (no request);
    Nada: Annual Leave Tue, then changed by hand to the Leave status; Zein: an approved request without a type on Mon;
    Yara: Annual Leave Sun, then cancelled. Everyone else planned at the Office, nobody signed in."""
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_teams','ews_team_members','ews_departments','ews_audit_log','ews_leave_requests','ews_leave_balances','ews_leave_schedule_snapshots','ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'lt%%' AND id<>$eid");
        foreach(['ews_feature_leave_type','ews_attendance_page_cards','ews_feature_attendance_cards','ews_schedule_types_config','ews_feature_week_lock'] as $o) delete_option($o);
        update_option('ews_working_days',[0,1,2,3,4],false);
        $wpdb->query("DELETE FROM {$p}ews_leave_types WHERE name NOT IN ('Annual Leave')");
        $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=0 WHERE name='Annual Leave'");
        foreach(['Sick Leave','Casual Leave'] as $n) $wpdb->insert($p.'ews_leave_types',['name'=>$n,'active'=>1,'deduct_balance'=>0,'annual_entitlement'=>0]);
        $wf=$wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='vacation'");
        $wpdb->query("DELETE FROM {$p}ews_approval_workflow_steps WHERE workflow_id=".(int)$wf);
        $wpdb->update($p.'ews_approval_workflows',['approval_mode'=>'NONE','active'=>0],['id'=>$wf]);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'lt-ops','active'=>1]); $ops=(int)$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $ids=['mona'=>$eid];
        foreach(['kareem'=>'Kareem Annual','amr'=>'Amr Sick','wael'=>'Wael Casual','hala'=>'Hala Holiday','sherif'=>'Sherif Hand','nada'=>'Nada Changed','zein'=>'Zein Untyped','yara'=>'Yara Cancelled'] as $k=>$n){
            $dn='lt'.$k;$wpdb->insert($p.'ews_employees',['name'=>$n,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$ops]);$ids[$k]=(int)$wpdb->insert_id;}
        $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true);
        [$dates,$sun]=$m->invoke(new EWS_Manager_V31_1(),gmdate('Y-m-d',strtotime(current_time('Y-m-d').' -7 days')));
        $ids['dates']=$dates; $ids['week']=gmdate('Y-m-d',$sun);
        $wpdb->insert($p.'ews_company_calendar',['event_date'=>$dates[4],'title'=>'National Day','event_type'=>'general_leave','active'=>1]);
        foreach($ids as $k=>$id){if(!is_int($id))continue; foreach($dates as $d) $wpdb->insert($p.'ews_schedule',['employee_id'=>$id,'work_date'=>$d,'status'=>'Office']);}
        $wpdb->update($p.'ews_schedule',['status'=>'Vacation'],['employee_id'=>$ids['sherif'],'work_date'=>$dates[3]]);
        foreach(['Annual Leave','Sick Leave','Casual Leave'] as $n) $ids['type_'.strtolower(strtok($n,' '))]=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_leave_types WHERE name=%s",$n));
        echo wp_json_encode($ids);
    """)
    return json.loads(out.splitlines()[-1])


ctx = seed()
D = ctx['dates']
W = '/app/?ews_view=attendance&week=' + ctx['week']
adm = Session('admin', 'admin')

# ---------------------------------------------------------------- recording leave the real way (wp-admin Leaves → Record leave)
st, lp, _ = adm.req('/wp-admin/admin.php?page=ews31-leaves')
n_rec = None
for form in re.findall(r'<form.*?</form>', lp, re.S):
    if 'name="action" value="ews_admin_leave_record"' in form:
        m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        n_rec = m.group(1) if m else None


def record(who, t, start, end):
    _, qs, _ = adm.post('ews_admin_leave_record', _wpnonce=n_rec, employee_id=ctx[who], leave_type_id=ctx['type_' + t], start_date=start, end_date=end, reason='e2e')
    return qs


for who, t, a, b in [('kareem', 'annual', 0, 1), ('amr', 'sick', 0, 0), ('wael', 'casual', 2, 2), ('hala', 'annual', 3, 4), ('nada', 'annual', 2, 2), ('zein', 'annual', 1, 1), ('yara', 'annual', 0, 0)]:
    qs = record(who, t, D[a], D[b])
    check('leave recorded for %s' % who, 'leave_recorded' in str(qs), qs)
# Nada's approved day changed by hand to another leave status; Zein's request has no type; Yara's leave is cancelled.
php("$wpdb->update($p.'ews_schedule',['status'=>'Leave'],['employee_id'=>%d,'work_date'=>'%s']);" % (ctx['nada'], D[2]))
php("$wpdb->update($p.'ews_leave_requests',['leave_type_id'=>0],['employee_id'=>%d]);" % ctx['zein'])
out = php("""
    $o=new EWS_Manager_V31_1(); wp_set_current_user(1);
    $id=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_requests WHERE employee_id=%d");
    $wpdb->update($p.'ews_leave_requests',['cancellation_status'=>'Pending'],['id'=>$id]);
    $r=$wpdb->get_row("SELECT r.*,t.deduct_balance FROM {$p}ews_leave_requests r LEFT JOIN {$p}ews_leave_types t ON t.id=r.leave_type_id WHERE r.id=$id");
    $emp=$wpdb->get_row("SELECT * FROM {$p}ews_employees WHERE id=%d");
    $m=new ReflectionMethod($o,'leave_apply_cancellation'); $m->setAccessible(true);
    var_export($m->invoke($o,$r,$emp,null,current_time('mysql')));
""" % (ctx['yara'], ctx['yara']))
check('Yara\'s leave is cancelled through the cancellation step', out.strip().endswith('true') and q("SELECT status FROM {p}ews_leave_requests WHERE employee_id=%d" % ctx['yara']) == [{'status': 'Cancelled'}], out)


def cell(page, eid, idx):
    """(result class, the text, the title of a typed label or None)"""
    m = re.search(r'<td class="ews-att-day[^"]*" data-employee="%d" data-day="%d"[^>]*>.*?<div class="ews-att-result ([a-z-]+)">(.*?)</div>' % (eid, idx), page, re.S)
    if not m:
        return None
    t = re.search(r'<span title="([^"]*)">', m.group(2))
    return (m.group(1), ' '.join(html.unescape(re.sub(r'<[^>]+>', ' ', m.group(2))).split()), html.unescape(t.group(1)) if t else None)


def stats(page):
    m = re.search(r'<div class="ews-att-summary wfo-att-stats([^"]*)">(.*?)\n    </div>', page, re.S)
    return (m.group(1), re.findall(r'class="ews-att-stat ([a-z]+)', m.group(2))) if m else (None, [])


def leave_card(page):
    m = re.search(r'class="ews-att-stat leave[^"]*"[^>]*>.*?<b[^>]*>(\d+)</b><small>([^<]*)</small>(?:<small class="wfo-lt-split">([^<]*)</small>)?', page, re.S)
    return (int(m.group(1)), html.unescape(m.group(2)), html.unescape(m.group(3) or '')) if m else None


# ---------------------------------------------------------------- off (the default): as before
st, off_page, _ = adm.req(W)
check('off: the page opens', st == 200 and 'MANAGER CONTROL CENTER' in off_page, st)
check('off: a day of approved leave shows "Leave", as before', cell(off_page, ctx['kareem'], 0) == ('leave', 'Leave', None), cell(off_page, ctx['kareem'], 0))
check('off: no Leave days card and the five cards as before', stats(off_page) == ('', ['people', 'present', 'late', 'absent', 'rate']) and 'Leave days' not in off_page and 'wfo-lt-split' not in off_page, stats(off_page))
php("update_option('ews_feature_leave_type',0,false);")
st, off0, _ = adm.req(W)
norm = lambda s: re.sub(r'(_wpnonce|data-nonce|name="_wpnonce" value)="[^"]*"', '', s)
check('off: switched off after being stored, the page is the same', norm(off0) == norm(off_page))

# ---------------------------------------------------------------- on
php("update_option('ews_feature_leave_type',1,false);")
st, page, _ = adm.req(W)
check('on: Annual Leave on both of Kareem\'s days', [cell(page, ctx['kareem'], i) for i in (0, 1)] == [('leave', 'Annual Leave', 'Annual Leave')] * 2, [cell(page, ctx['kareem'], i) for i in (0, 1)])
check('on: Sick Leave and Casual Leave', cell(page, ctx['amr'], 0) == ('leave', 'Sick Leave', 'Sick Leave') and cell(page, ctx['wael'], 2) == ('leave', 'Casual Leave', 'Casual Leave'),
      (cell(page, ctx['amr'], 0), cell(page, ctx['wael'], 2)))
check('on: the leave day before a holiday shows its type, the holiday shows "Leave" with its name', cell(page, ctx['hala'], 3)[2] == 'Annual Leave' and cell(page, ctx['hala'], 4) == ('leave', 'Leave National Day', None),
      (cell(page, ctx['hala'], 3), cell(page, ctx['hala'], 4)))
check('on: Vacation set by hand (no request) shows "Leave"', cell(page, ctx['sherif'], 3) == ('leave', 'Leave', None), cell(page, ctx['sherif'], 3))
check('on: an approved day changed by hand to another leave status shows "Leave"', cell(page, ctx['nada'], 2) == ('leave', 'Leave', None), cell(page, ctx['nada'], 2))
check('on: an approved request without a type shows "Leave"', cell(page, ctx['zein'], 1) == ('leave', 'Leave', None), cell(page, ctx['zein'], 1))
check('on: a cancelled leave is back to its plan (Absent, no sign-in)', cell(page, ctx['yara'], 0)[0] == 'absent', cell(page, ctx['yara'], 0))
check('on: the six cards, Leave days after Absent days', stats(page) == (' has-leave is-6', ['people', 'present', 'late', 'absent', 'leave', 'rate']), stats(page))
check('on: Leave days counts 8 (holiday left out), Other leave = hand-set, changed and untyped', leave_card(page) == (8, 'Leave days', 'Annual 3 · Casual 1 · Sick 1 · Other leave 3'), leave_card(page))
check('on: the other cards are unchanged', stats(page)[1].count('rate') == 1 and re.search(r'<b>0%</b><small>Attendance rate</small>', page) is not None)

# renamed and deactivated types
php("$wpdb->update($p.'ews_leave_types',['name'=>'Casual (paid)'],['id'=>%d]); $wpdb->update($p.'ews_leave_types',['active'=>0],['id'=>%d]);" % (ctx['type_casual'], ctx['type_sick']))
st, page, _ = adm.req(W)
check('a renamed type shows its new name; a deactivated one still shows', cell(page, ctx['wael'], 2)[2] == 'Casual (paid)' and cell(page, ctx['amr'], 0)[2] == 'Sick Leave', (cell(page, ctx['wael'], 2), cell(page, ctx['amr'], 0)))
php("$wpdb->update($p.'ews_leave_types',['name'=>'<b>Casual & Co</b>'],['id'=>%d]);" % ctx['type_casual'])
st, page, _ = adm.req(W)
check('a type name is escaped', '<b>Casual' not in page and '&lt;b&gt;Casual &amp; Co&lt;/b&gt;' in page)
php("$wpdb->update($p.'ews_leave_types',['name'=>'Casual Leave','active'=>1],['id'=>%d]); $wpdb->update($p.'ews_leave_types',['active'=>1],['id'=>%d]);" % (ctx['type_casual'], ctx['type_sick']))

# ---------------------------------------------------------------- the cards shown (Feature Configuration)
FP = '/wp-admin/admin.php?page=ews31-features'
st, fpage, _ = adm.req(FP)
m = re.search(r'name="action" value="ews31_features_save".*?name="_wpnonce" value="([^"]+)"|name="_wpnonce" value="([^"]+)".*?name="action" value="ews31_features_save"', fpage, re.S)
fn = (m.group(1) or m.group(2)) if m else 'x'
check('Features: the Leave type switch and the six cards, all ticked', 'name="leave_type_enabled"' in fpage and len(re.findall(r'name="att_page_cards\[\]" value="[a-z]+"\s+checked', fpage)) == 6,
      re.findall(r'name="att_page_cards\[\]"[^>]*>', fpage))


def save(cards, lt=1):
    data = {'action': 'ews31_features_save', '_wpnonce': fn, 'leave_type_present': 1, 'att_page_cards_present': 1, 'att_page_cards[]': cards}
    if lt:
        data['leave_type_enabled'] = 1
    return adm.req('/wp-admin/admin-post.php', data)[0]


audits = lambda a: int(q("SELECT COUNT(*) c FROM {p}ews_audit_log WHERE action='%s'" % a)[0]['c'])
save(['people', 'present', 'late', 'absent', 'leave', 'rate'])
check('saving every card ticked stores nothing (as before) and audits nothing', q("SELECT option_id FROM {p}options WHERE option_name='ews_attendance_page_cards'") == [] and audits('attendance_page_cards_update') == 0)
check('Leave type stays on and is not audited again (unchanged)', php("echo (int)get_option('ews_feature_leave_type');").strip() == '1' and audits('leave_type_feature_update') == 0)
save(['late', 'absent', 'leave', 'rate'])
check('hiding Employees and Present days is stored and audited', php("echo wp_json_encode(get_option('ews_attendance_page_cards'));").strip() == '{"hidden":["people","present"]}' and audits('attendance_page_cards_update') == 1)
st, page, _ = adm.req(W)
check('...the page shows the four others', stats(page) == (' has-leave is-4', ['late', 'absent', 'leave', 'rate']), stats(page))
st, fpage, _ = adm.req(FP)
check('...and Features shows them unticked', re.search(r'value="people"\s+checked', fpage) is None and re.search(r'value="late"\s+checked', fpage) is not None)
st, ov, _ = adm.req('/wp-admin/admin.php?page=ews31-settings-overview')
check('...and the Settings overview lists the setting as changed', re.search(r'<tr data-option="ews_attendance_page_cards" class="is-changed">', ov) is not None, st)
php("update_option('ews_feature_leave_type',0,false);")
st, page, _ = adm.req(W)
check('Leave type off: Leave days goes, the hidden ones stay hidden', stats(page) == (' is-3', ['late', 'absent', 'rate']), stats(page))
php("update_option('ews_feature_leave_type',1,false);")
save([])
st, page, _ = adm.req(W)
check('every card hidden: no row of cards', 'ews-att-summary wfo-att-stats' not in page and 'Schedule Controls' in page)
save(['people', 'present', 'late', 'absent', 'leave', 'rate'], lt=0)
check('ticking every card again deletes the setting; switching Leave type off is audited', q("SELECT option_id FROM {p}options WHERE option_name='ews_attendance_page_cards'") == []
      and php("echo (int)get_option('ews_feature_leave_type');").strip() == '0' and audits('leave_type_feature_update') == 1)
php("update_option('ews_feature_leave_type',1,false);")

# ---------------------------------------------------------------- the Leave days list (Attendance cards on)
php("update_option('ews_feature_attendance_cards',1,false);")
st, page, _ = adm.req(W)
nonce = (re.search(r'id="wfo-ac-sheet"[^>]*data-nonce="([^"]+)"', page) or [None, 'x'])[1]
check('cards on: Leave days is a card that opens its list', re.search(r'<button type="button" class="ews-att-stat leave wfo-ac-card"[^>]*data-ews-card="leave"', page) is not None and leave_card(page)[0] == 8, leave_card(page))
texts = json.loads(html.unescape(re.search(r'data-text="([^"]*)"', page).group(1)))
check('...with its texts', texts['title'].get('leave') == 'Leave days' and texts['empty'].get('leave') == 'No leave days this week.')


def api(kind, **kw):
    args = {'action': 'ews_att_cards', '_wpnonce': nonce, 'kind': kind, 'week': ctx['week']}
    args.update(kw)
    st, body, _ = adm.req('/wp-admin/admin-post.php?' + urllib.parse.urlencode(args))
    try:
        return st, json.loads(body)
    except ValueError:
        return st, {'raw': body[:300]}


st, j = api('leave')
rows = j.get('data', {}).get('rows', [])
check('the list: 8 days, as the card', j.get('success') and j['data']['card'] == 8 and j['data']['total'] == 8, j)
check('...and the line under the card, for the card to follow the list', j['data'].get('line') == 'Annual 3 · Casual 1 · Sick 1 · Other leave 3', j['data'].get('line'))
got = sorted((r['name'], r['date'], r['type']) for r in rows)
want = sorted([('Kareem Annual', D[0], 'Annual Leave'), ('Kareem Annual', D[1], 'Annual Leave'), ('Amr Sick', D[0], 'Sick Leave'), ('Wael Casual', D[2], 'Casual Leave'),
               ('Hala Holiday', D[3], 'Annual Leave'), ('Sherif Hand', D[3], ''), ('Nada Changed', D[2], ''), ('Zein Untyped', D[1], '')])
check('...each day with its type ("" for hand-set or untyped), no holiday', got == want, got)
check('...by day: the dates in order', [r['date'] for r in rows] == sorted(r['date'] for r in rows))
st, j = api('leave', order='employee')
names = [r['name'] for r in j['data']['rows']]
check('...by employee: each person\'s days together, in date order', len(names) == 8 and all(names.index(n) + names.count(n) - 1 == len(names) - 1 - names[::-1].index(n) for n in names)
      and [r['date'] for r in j['data']['rows'] if r['name'] == 'Kareem Annual'] == [D[0], D[1]], names)
php("update_option('ews_feature_leave_type',0,false);")
st, j = api('leave')
check('Leave type off: the leave list is "not available" (no retry)', st == 404 and j.get('data', {}).get('reason') == 'off', (st, j))
st, page, _ = adm.req(W)
check('Leave type off: no Leave card and no leave texts', 'data-ews-card="leave"' not in page and '"leave":"Leave days"' not in html.unescape(page))
php("update_option('ews_feature_leave_type',1,false); update_option('ews_attendance_page_cards',['hidden'=>['leave','people']],false);")
st, page, _ = adm.req(W)
check('cards on and hidden: those buttons are not printed', 'data-ews-card="leave"' not in page and 'data-ews-card="people"' not in page and 'data-ews-card="late"' in page)
php("delete_option('ews_attendance_page_cards'); delete_option('ews_feature_attendance_cards'); delete_option('ews_feature_leave_type');")


print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
