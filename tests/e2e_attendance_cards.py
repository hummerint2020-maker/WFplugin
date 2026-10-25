"""Attendance cards you can open (3.31.125, docs/tasks/attendance-cards.md, mockups docs/mockups/attendance-cards/).

With the switch on, each card at the top of the manager Attendance page (Employees, Present days, Late days, Absent days,
Attendance rate) opens a list of who and when, loaded from admin-post.php?action=ews_att_cards. The list always counts what the
card counts (same people, week, search, team, branch and department scope), shows the sign-in and sign-out times, the minutes
late against each person's shift, or where an absent person was planned to be. Off (the default) the page is as before and the
endpoint answers nothing.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_attendance_cards.py <state-dir>
       ... --record <dir>   writes the page with the switch off (normalised) for each variant into <dir>
       ... --compare <dir>  compares the page with the switch off with what --record wrote (byte for byte)
       ... --seed [big]     only seeds (switch on), for tests/attendance_cards_browser.js
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.error, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, d, php, q, results, wp, HERE  # noqa: E402

MODE = sys.argv[2] if len(sys.argv) > 2 else ''
GOLDEN = sys.argv[3] if len(sys.argv) > 3 else ''
N_BIG = 70


def seed():
    """Last week (Sun-Thu working days): on time, late, absent, leave, a company holiday, a missing sign-out, an old
    late_sign_in event, an overnight shift, and 70 more people for the paged list (off until big(True))."""
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_teams','ews_team_members','ews_departments','ews_audit_log','ews_employee_shift_periods'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'ac%%' AND id<>$eid");
        delete_option('ews_feature_attendance_cards'); delete_option('ews_schedule_types_config');
        update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false);
        update_option('ews_working_days',[0,1,2,3,4],false);
        update_option('ews_shifts',[['id'=>201,'name'=>'Night','start'=>'22:00','end'=>'06:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>1,'active'=>1]],false);
        $wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'ac-ops','active'=>1]); $ops=(int)$wpdb->insert_id;
        $wpdb->insert($p.'ews_departments',['name'=>'Sales','code'=>'ac-sales','active'=>1]); $sales=(int)$wpdb->insert_id;
        $wpdb->update($p.'ews_employees',['name'=>'Mona Manager','department_id'=>$ops],['id'=>$eid]);
        $mk=function($name,$dep)use($wpdb,$p){$dn='ac'.strtolower(strtok($name,' '));$wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','active'=>1,'attendance_enabled'=>1,'department_id'=>$dep]);return (int)$wpdb->insert_id;};
        $ids=['mona'=>$eid];
        foreach(['omar'=>'Omar Ontime','lina'=>'Lina Late','ali'=>'Ali Absent','rana'=>'Rana Leave','nour'=>'Nour Noout','ziad'=>'Ziad Night'] as $k=>$n) $ids[$k]=$mk($n,$ops);
        $ids['sam']=$mk('Sam Sales',$sales);
        $wpdb->update($p.'ews_employees',['default_shift_id'=>201],['id'=>$ids['ziad']]);
        $wpdb->insert($p.'ews_employee_shift_periods',['employee_id'=>$ids['ziad'],'shift_id'=>201,'from_date'=>'2000-01-01','to_date'=>null]);
        $wpdb->insert($p.'ews_teams',['name'=>'Alpha','active'=>1]); $team=(int)$wpdb->insert_id;
        foreach(['omar','lina','ali'] as $k) $wpdb->insert($p.'ews_team_members',['team_id'=>$team,'employee_id'=>$ids[$k],'active'=>1]);
        $m=new ReflectionMethod('EWS_Manager_V31_1','week_dates_configured'); $m->setAccessible(true);
        [$dates,$sun]=$m->invoke(new EWS_Manager_V31_1(),gmdate('Y-m-d',strtotime(current_time('Y-m-d').' -7 days')));
        $ids['dates']=$dates; $ids['week']=gmdate('Y-m-d',$sun);
        $wpdb->insert($p.'ews_company_calendar',['event_date'=>$dates[4],'title'=>'National Day','event_type'=>'general_leave','active'=>1]);
        $plan=function($k,$d,$st)use($wpdb,$p,&$ids){$wpdb->insert($p.'ews_schedule',['employee_id'=>$ids[$k],'work_date'=>$d,'status'=>$st]);};
        $log=function($k,$d,$type,$at)use($wpdb,$p,&$ids){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids[$k],'user_id'=>1,'work_date'=>$d,'event_type'=>$type,'event_at'=>$at,'scheduled_status'=>'Office']);};
        foreach($dates as $i=>$d){
            foreach(['mona','omar','lina','ali','rana','nour','ziad','sam'] as $k) $plan($k,$d,($k==='ali'&&$i===3)?'WFH':(($k==='rana'&&$i===1)?'Leave':'Office'));
            if($i===4) continue;   // the holiday
            foreach(['mona','omar'] as $k){$log($k,$d,'sign_in',"$d 08:55:00");$log($k,$d,'sign_out',"$d 17:05:00");}
            $log('lina',$d,'sign_in',$i===1?"$d 09:23:00":"$d 08:58:00"); $log('lina',$d,'sign_out',"$d 17:00:00");
            if($i!==2&&$i!==3){$log('ali',$d,'sign_in',"$d 08:59:00");$log('ali',$d,'sign_out',"$d 17:00:00");}
            if($i===2) $log('rana',$d,'late_sign_in',"$d 08:50:00"); elseif($i!==1){$log('rana',$d,'sign_in',"$d 09:00:00");$log('rana',$d,'sign_out',"$d 17:00:00");}
            $log('nour',$d,'sign_in',"$d 09:05:00"); if($i!==0) $log('nour',$d,'sign_out',"$d 17:00:00");
            $next=gmdate('Y-m-d',strtotime("$d +1 day"));
            $log('ziad',$d,'sign_in',$i===1?"$next 00:15:00":"$d 21:58:00"); $log('ziad',$d,'sign_out',"$next 06:00:00");
            $log('sam',$d,'sign_in',"$d 09:40:00"); $log('sam',$d,'sign_out',"$d 17:00:00");
        }
        // 70 more people in Ops, planned at the Office, who never signed in (inactive until big(True))
        for($i=1;$i<=%(n)d;$i++){$wpdb->insert($p.'ews_employees',['name'=>sprintf('Big Person %%02d',$i),'domain_name'=>'acbig'.$i,'email'=>"acbig$i@example.com",'active'=>0,'attendance_enabled'=>1,'department_id'=>$ops]);
            $b=(int)$wpdb->insert_id; foreach($dates as $d) $wpdb->insert($p.'ews_schedule',['employee_id'=>$b,'work_date'=>$d,'status'=>'Office']);}
        echo wp_json_encode($ids);
    """ % {'n': N_BIG})
    return json.loads(out.splitlines()[-1])


def big(on):
    php("$wpdb->query(\"UPDATE {$p}ews_employees SET active=%d WHERE domain_name LIKE 'acbig%%'\");" % (1 if on else 0))


def manager(on):
    php("$u=new WP_User((int)get_user_by('login','emp1')->ID); %s" % ("$u->add_cap('ews_manage_attendance');" if on else "$u->remove_cap('ews_manage_attendance');"))


def switch(on):
    php("update_option('ews_feature_attendance_cards',1,false);" if on else "delete_option('ews_feature_attendance_cards');")


def normalise(page):
    """Nonces change on every request and the version on every release; nothing else should."""
    page = re.sub(r'ver=3\.\d+\.\d+', 'ver=V', page)
    page = re.sub(r'(?i)(nonce[^0-9a-f<>]{0,25})[0-9a-f]{10}\b', r'\1N', page)
    return re.sub(r'(name="_wpnonce" value=")[0-9a-f]{10}"', r'\1N"', page)


def variants(ctx):
    """(name, session, url) for the off pages: unpaged, paged with search and team, a manager's scope, no working days."""
    W = '/app/?ews_view=attendance&week=' + ctx['week']
    return [('small-admin', 'adm', W, False), ('small-manager', 'emp', W, False),
            ('big-admin', 'adm', W, True), ('big-search', 'adm', W + '&q=big+person+0', True), ('big-team', 'adm', W + '&team=Alpha', True),
            ('big-manager-page2', 'emp', W + '&pg=2', True)]


def golden(ctx, sessions, mode, where):
    os.makedirs(where, exist_ok=True)
    switch(False)
    manager(True)
    ok = True
    for name, who, url, is_big in variants(ctx):
        big(is_big)
        page = normalise(sessions[who].req(url)[1])
        path = os.path.join(where, name + '.html')
        if mode == '--record':
            open(path, 'w').write(page)
        else:
            same = os.path.exists(path) and open(path).read() == page
            ok = ok and same
            check('switch off: the page is byte for byte what it was before the release (%s)' % name, same, name)
    php("update_option('ews_working_days',[],false);")
    page = normalise(sessions['adm'].req('/app/?ews_view=attendance&week=' + ctx['week'])[1])
    php("update_option('ews_working_days',[0,1,2,3,4],false);")
    path = os.path.join(where, 'no-working-days.html')
    if mode == '--record':
        open(path, 'w').write(page)
    else:
        check('switch off: the page is byte for byte what it was before the release (no working days)', os.path.exists(path) and open(path).read() == page)
    big(False)
    return ok


if MODE == '--compare' and os.path.exists(os.path.join(GOLDEN, 'ctx.json')):
    ctx = json.load(open(os.path.join(GOLDEN, 'ctx.json')))   # the same people as --record (ids are in the page)
else:
    ctx = seed()
    if MODE == '--record':
        os.makedirs(GOLDEN, exist_ok=True)
        json.dump(ctx, open(os.path.join(GOLDEN, 'ctx.json'), 'w'))
if MODE == '--seed':   # for tests/attendance_cards_browser.js: the people, the switch on, the manager's permission
    big(GOLDEN == 'big')
    switch(True)
    manager(True)
    print(json.dumps(ctx))
    sys.exit(0)
S = {'adm': Session('admin', 'admin'), 'emp': Session('emp1', 'emp1pass')}
if MODE in ('--record', '--compare'):
    golden(ctx, S, MODE, GOLDEN)
    manager(False)
    if MODE == '--record':
        print('recorded into', GOLDEN)
        sys.exit(0)
    print(f'{sum(results)} / {len(results)}')
    sys.exit(0 if all(results) else 1)


# ---------------------------------------------------------------- helpers
adm, emp = S['adm'], S['emp']
W = '/app/?ews_view=attendance&week=' + ctx['week']
D = ctx['dates']
IDS = {k: ctx[k] for k in ('mona', 'omar', 'lina', 'ali', 'rana', 'nour', 'ziad', 'sam')}


def sheet_nonce(page):
    m = re.search(r'id="wfo-ac-sheet"[^>]*data-nonce="([^"]+)"', page)
    return m.group(1) if m else 'x'


def api(sess, nonce, kind, **kw):
    args = {'action': 'ews_att_cards', '_wpnonce': nonce, 'kind': kind, 'week': ctx['week']}
    args.update(kw)
    st, body, _ = sess.req('/wp-admin/admin-post.php?' + urllib.parse.urlencode(args))
    try:
        return st, json.loads(body)
    except ValueError:
        return st, {'raw': body[:300]}


def all_rows(sess, nonce, kind, order='day', **kw):
    rows, offset, guard = [], 0, 0
    while offset is not None and guard < 50:
        st, j = api(sess, nonce, kind, order=order, offset=offset, **kw)
        if not j.get('success'):
            return None, j
        rows += j['data']['rows']
        offset, guard = j['data']['next'], guard + 1
    return rows, j['data']


def card(page, kind):
    m = re.search(r'data-ews-card="%s".*?<b data-ac-card-n>(\d+)%%?</b>' % kind, page, re.S)
    return int(m.group(1)) if m else None


# ---------------------------------------------------------------- off (the default)
manager(True)
switch(False)
page = adm.req(W)[1]
check('off: the cards are as before (no button, no sheet, no script)', 'data-ews-card' not in page and 'wfo-ac-sheet' not in page and 'attendance-cards.js' not in page and '<div class="ews-att-stat late">' in page)
st, j = api(adm, 'x', 'late')
check('...and the list answers nothing (404, "off")', st == 404 and j.get('data', {}).get('reason') == 'off', (st, j))
q0 = php("""$u=get_user_by('login','admin'); wp_set_current_user($u->ID); $_GET=['ews_view'=>'attendance','week'=>'%s'];
    $m=new ReflectionMethod('EWS_Manager_V31_1','attendance_content'); $m->setAccessible(true); $m->invoke(new EWS_Manager_V31_1());
    $c=function($on)use($wpdb,$m){ if($on) update_option('ews_feature_attendance_cards',1,false); else delete_option('ews_feature_attendance_cards');
        wp_cache_flush(); $m->invoke(new EWS_Manager_V31_1()); $q=$wpdb->num_queries; $m->invoke(new EWS_Manager_V31_1()); return $wpdb->num_queries-$q; };
    $off=$c(false); $on=$c(true); delete_option('ews_feature_attendance_cards'); echo $off.' '.$on;""" % ctx['week']).split()[-2:]
check('the page costs the same queries with the switch on as off (the lists load only when a card is opened)', q0[0] == q0[1], q0)

# ---------------------------------------------------------------- the Features page
FEAT = '/wp-admin/admin.php?page=ews31-features'
fp = adm.req(FEAT)[1]
check('the switch is on the Features page, off', re.search(r'name="attendance_cards_enabled"(?![^>]*checked)', fp) is not None and 'name="attendance_cards_present"' in fp and 'Attendance card details' in fp)


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
            opt = re.search(r'<option value="([^"]*)"[^>]*selected', sel) or re.search(r'<option value="([^"]*)"', sel)
            data.append((name.group(1), html.unescape(opt.group(1)) if opt else ''))
            continue
        v = re.search(r'value="([^"]*)"', tag)
        data.append((name.group(1), html.unescape(v.group(1)) if v else ''))
    return data


fields = [(k, v) for k, v in form_fields(fp, 'ews31_features_save') if k != 'attendance_cards_enabled'] + [('attendance_cards_enabled', '1')]
adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(fields).encode())
on = php("echo (int)get_option('ews_feature_attendance_cards');").strip()
audit = q("SELECT details FROM {p}ews_audit_log WHERE action='feature_update' ORDER BY id DESC LIMIT 1")
check('...saving it turns it on, in the audit log', on == '1' and audit and 'attendance_cards=enabled' in audit[0]['details'], (on, audit))
older = [(k, v) for k, v in form_fields(adm.req(FEAT)[1], 'ews31_features_save') if not k.startswith('attendance_cards')]
adm.req('/wp-admin/admin-post.php', urllib.parse.urlencode(older).encode())
check('...a form from before the release (no field) keeps it', php("echo (int)get_option('ews_feature_attendance_cards');").strip() == '1')

# ---------------------------------------------------------------- on: the cards and their lists
switch(True)
page = adm.req(W)[1]
check('on: each card is a button that opens the sheet', page.count('data-ews-card=') == 5 and re.search(r'<button type="button" class="ews-att-stat late wfo-ac-card" data-wfo-sheet="wfo-ac-sheet" data-ews-card="late" aria-haspopup="dialog"', page) is not None
      and 'id="wfo-ac-sheet"' in page and 'attendance-cards.js' in page)
N = sheet_nonce(page)
for kind in ('people', 'present', 'late', 'absent'):
    rows, d = all_rows(adm, N, kind)
    keys = [(r['employee_id'], r.get('date')) for r in rows or []]
    check('%s: the list counts what the card counts (%s), page after page, no row twice' % (kind, card(page, kind)), rows is not None and len(rows) == card(page, kind) == d['total'] == d['card'] and len(set(keys)) == len(keys), (card(page, kind), d))
st, j = api(adm, N, 'rate')
check('rate: the same rate as the card', j.get('success') and j['data']['card'] == card(page, 'rate'), j)

late, _ = all_rows(adm, N, 'late')
by = {(r['employee_id'], r['date']): r for r in late}
lina = by.get((IDS['lina'], D[1]), {})
check('late: Lina, signed in 09:23 (start 09:00, grace 10): 23 minutes late, with her sign-out', lina.get('in') == '09:23' and lina.get('late_min') == 23 and lina.get('out') == '17:00' and not lina.get('missing_out'), lina)
ziad = by.get((IDS['ziad'], D[1]), {})
check('...Ziad on the night shift (22:00), signed in 00:15 the next day: 135 minutes, marked "next day"', ziad.get('in') == '00:15' and ziad.get('late_min') == 135 and ziad.get('next_day') is True, ziad)
rana = by.get((IDS['rana'], D[2]), {})
check('...Rana, an old "late_sign_in" record: Late, without minutes (owner decision 9)', rana and rana.get('late_min') is None, rana)
check('...the holiday is in no list', all(r['date'] != D[4] for r in late))
day1 = [r['employee_id'] for r in late if r['date'] == D[1]]
check('...within a day the most minutes late come first (owner decision 8)', day1.index(IDS['ziad']) < day1.index(IDS['lina']), day1)
bye, _ = all_rows(adm, N, 'late', order='employee')
check('...by employee: the same rows, each person together', sorted((r['employee_id'], r['date']) for r in bye) == sorted(by) and [r['employee_id'] for r in bye] == sorted([r['employee_id'] for r in bye], key=[r['employee_id'] for r in bye].index))

present, _ = all_rows(adm, N, 'present')
pk = {(r['employee_id'], r['date']): r for r in present}
check('present: on time only (Lina\'s late day is not there)', (IDS['lina'], D[1]) not in pk and (IDS['lina'], D[0]) in pk)
nour = pk.get((IDS['nour'], D[0]), {})
check('...Nour forgot to sign out on a past day: "No sign-out"', nour.get('in') == '09:05' and nour.get('out') is None and nour.get('missing_out') is True, nour)
d0 = [r['in'] for r in present if r['date'] == D[0]]
check('...within a day by sign-in time (owner decision 8)', d0 == sorted(d0), d0)

absent, _ = all_rows(adm, N, 'absent')
check('absent: Ali, with where he was planned (Office, then WFH)', [(r['employee_id'], r['date'], r['plan']) for r in absent] == [(IDS['ali'], D[2], 'Office'), (IDS['ali'], D[3], 'WFH')], absent)

people, _ = all_rows(adm, N, 'people')
pp = {r['employee_id']: r for r in people}
check('employees: each person\'s week (Rana: 2 present, 1 late, 1 leave, 1 holiday)', {k: pp.get(IDS['rana'], {}).get(k) for k in ('present', 'late', 'absent', 'leave', 'holiday')} == {'present': 2, 'late': 1, 'absent': 0, 'leave': 1, 'holiday': 1}, pp.get(IDS['rana']))
check('...a company holiday counts apart from leave (owner decision 5)', all(r['holiday'] == 1 for r in people), [r['holiday'] for r in people])

rate = j['data']
check('rate: each day, and no rate on the holiday', len(rate['days']) == len(D) and rate['days'][4]['rate'] is None and all(x['rate'] is not None for x in rate['days'][:4]), rate['days'])
check('...the lowest: Ali (2 of 4 days) and nobody at 100%', rate['lowest'] and rate['lowest'][0]['employee_id'] == IDS['ali'] and rate['lowest'][0]['attended'] == 2 and rate['lowest'][0]['required'] == 4 and all(p['rate'] < 100 for p in rate['lowest']), rate['lowest'])

# ---------------------------------------------------------------- scope: a department manager, a long list, search and team
mpage = emp.req(W)[1]
MN = sheet_nonce(mpage)
mrows, md = all_rows(emp, MN, 'late')
check('a department manager: only their department (Sam in Sales is never there)', mrows is not None and IDS['sam'] not in [r['employee_id'] for r in mrows] and len(mrows) == card(mpage, 'late'), (card(mpage, 'late'), md))
for crafted in ({'team': 'Alpha'}, {'q': 'sam'}, {'branch': '999'}, {'week': D[0]}):
    rows, _ = all_rows(emp, MN, 'late', **crafted)
    check('...even with a crafted %s' % list(crafted)[0], rows is not None and IDS['sam'] not in [r['employee_id'] for r in rows], crafted)
big(True)
bpage = adm.req(W)[1]
BN = sheet_nonce(bpage)
rows, d = all_rows(adm, BN, 'absent')
check('a long list (78 people): Absent loads 20 at a time and counts the card (%s)' % card(bpage, 'absent'), rows is not None and len(rows) == card(bpage, 'absent') == d['total'] and len(rows) > 20, card(bpage, 'absent'))
st, j = api(adm, BN, 'absent')
check('...the first page is 20 rows, then "next"', j['data']['next'] == 20 and len(j['data']['rows']) == 20)
spage = adm.req(W + '&q=big+person+0')[1]
rows, d = all_rows(adm, sheet_nonce(spage), 'absent', q='big person 0')
check('...a search (applied on the server for a long list): the list follows it (%s)' % card(spage, 'absent'), rows is not None and len(rows) == card(spage, 'absent') and all(r['name'].startswith('Big Person 0') for r in rows), card(spage, 'absent'))
tpage = adm.req(W + '&team=Alpha')[1]
rows, d = all_rows(adm, sheet_nonce(tpage), 'people', team='Alpha')
check('...a team: only the team (Alpha: Omar, Lina, Ali)', rows is not None and sorted(r['employee_id'] for r in rows) == sorted([IDS['omar'], IDS['lina'], IDS['ali']]) and len(rows) == card(tpage, 'people'), [r['name'] for r in rows or []])


def endpoint_queries(kind):
    """The list's query count for the admin, measured in one PHP process (wp_send_json() ends it; the shutdown prints)."""
    out = php("""$u=get_user_by('login','admin'); wp_set_current_user($u->ID);
        $_GET=$_REQUEST=['action'=>'ews_att_cards','kind'=>'%s','week'=>'%s','_wpnonce'=>wp_create_nonce('ews_att_cards')];
        $o=new EWS_Manager_V31_1(); $q0=$wpdb->num_queries;
        register_shutdown_function(function()use($wpdb,$q0){ echo "\nQ=".($wpdb->num_queries-$q0); });
        add_filter('wp_die_handler',function(){return function(){};});
        $o->att_cards_data();""" % (kind, ctx['week']))
    m = re.search(r'Q=(\d+)', out)
    return int(m.group(1)) if m else -1


big(False)
small = {k: endpoint_queries(k) for k in ('late', 'absent', 'people')}
big(True)
large = {k: endpoint_queries(k) for k in ('late', 'absent', 'people')}
check('a list costs the same queries for 8 people as for 78 (no query per person): %s / %s' % (small, large), small == large and all(v > 0 for v in small.values()), (small, large))
big(False)

# ---------------------------------------------------------------- the endpoint refuses
st, j = api(adm, 'bad', 'late')
check('a wrong nonce: 403, nothing returned', st == 403 and j.get('data', {}).get('reason') == 'nonce' and 'rows' not in json.dumps(j), (st, j))
st, j = api(adm, N, 'everything')
check('an unknown list: 400', st == 400, (st, j))
st, j = api(adm, N, 'late', offset='-5', order='junk')
check('...junk offset and order are clamped (first page, by day)', j.get('success') and j['data']['offset'] == 0 and j['data']['order'] == 'day', j)
manager(False)
st, j = api(emp, MN, 'late')
check('without the Attendance permission: 403', st == 403 and j.get('data', {}).get('reason') == 'access', (st, j))
manager(True)
try:
    anon = urllib.request.urlopen(B + '/wp-admin/admin-post.php?action=ews_att_cards&kind=late&week=' + ctx['week'], timeout=30).read().decode('utf-8', 'replace')
except urllib.error.HTTPError as e:
    anon = e.read().decode('utf-8', 'replace')
check('signed out: no list (the action is for signed-in people only)', '"success":true' not in anon and 'employee_id' not in anon, anon[:200])
php("$wpdb->update($p.'ews_employees',['name'=>'<script>x</script> Omar'],['id'=>%d]);" % IDS['omar'])
st, body, _ = adm.req('/wp-admin/admin-post.php?' + urllib.parse.urlencode({'action': 'ews_att_cards', '_wpnonce': N, 'kind': 'people', 'week': ctx['week']}))
check('a name with markup is data in the JSON, never markup (the page draws it with textContent)', json.loads(body)['data']['rows'] and any(r['name'] == '<script>x</script> Omar' for r in json.loads(body)['data']['rows']), body[:200])
php("$wpdb->update($p.'ews_employees',['name'=>'Omar Ontime'],['id'=>%d]);" % IDS['omar'])

# ---------------------------------------------------------------- today: "No sign-out" only after the shift
import e2e_support  # noqa: E402
TODAY = e2e_support.d(0)
php("""$e=%d; $t='%s'; $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_time_logs WHERE employee_id=%%d AND work_date=%%s",$e,$t));
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%%d AND work_date=%%s",$e,$t)); $wpdb->insert($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$t,'status'=>'Office']);
    $wpdb->insert($p.'ews_time_logs',['employee_id'=>$e,'user_id'=>1,'work_date'=>$t,'event_type'=>'sign_in','event_at'=>$t.' 00:01:00','scheduled_status'=>'Office']);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_working_hours',['start'=>'00:00','normal_until'=>'02:00','end'=>'23:59'],false);""" % (IDS['omar'], TODAY))
tp = adm.req('/app/?ews_view=attendance&week=' + TODAY)[1]
rows, _ = all_rows(adm, sheet_nonce(tp), 'present', week=TODAY)
mine = [r for r in rows or [] if r['employee_id'] == IDS['omar'] and r['date'] == TODAY]
check('today, still within the shift: no "No sign-out" yet (owner decision 7)', mine and mine[0]['missing_out'] is False, mine)
php("update_option('ews_working_days',[0,1,2,3,4],false); update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false);")

switch(False)
manager(False)
php("""$wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'ac%%' AND domain_name<>'emp1'"); $wpdb->query("DELETE FROM {$p}ews_departments WHERE code LIKE 'ac-%%'");
    $wpdb->query("DELETE FROM {$p}ews_employee_shift_periods"); delete_option('ews_shifts');""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
