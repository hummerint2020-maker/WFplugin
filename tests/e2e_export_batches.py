"""Exports in batches (3.31.149, docs/tasks/performance-2.md): the Sign In / Out CSV read in windows
of days and the Report Center's exports read a group of employees at a time give the same file as
one read of everything; a failed read sends no file; memory does not follow the period.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_export_batches.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import io, os, re, sys, urllib.request, zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, results, wp, HERE  # noqa: E402

START, END = d(-130), d(-11)
B = 'http://127.0.0.1:8080'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        foreach(['ews_time_logs','ews_schedule','ews_company_calendar','ews_break_sessions','ews_audit_log','ews_overtime_requests'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        delete_option('ews_schedule_types_config'); delete_option('ews_shifts');
        update_option('ews_working_days',[0,1,2,3,4,5],false);
        update_option('ews_working_hours',['start'=>'09:00','normal_until'=>'11:00','end'=>'17:00'],false); update_option('ews_grace_period',10,false);
        update_option('ews_feature_breaks',1,false); update_option('ews_break_duration_minutes',30,false);
        $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'xb%%'");
        $start=strtotime('%(start)s');$end=strtotime('%(end)s');$ids=[];
        for($i=0;$i<40;$i++){
            $name=$i%%7===0?'Same Name':($i%%7===1?'same name':'Batch '.chr(65+$i%%26).' '.$i);
            $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>'xb'.$i,'email'=>'xb'.$i.'@example.com','active'=>1,'attendance_enabled'=>1]);$ids[]=$wpdb->insert_id;
        }
        $vals=[];$sched=[];$n=0;
        foreach($ids as $k=>$eid){
            for($t=$start;$t<=$end;$t+=86400){
                $day=date('Y-m-d',$t);$r=($k*7+(int)date('z',$t))%%11;
                $sched[]=$wpdb->prepare('(%%d,%%s,%%s)',$eid,$day,$r===3?'Vacation':($r===4?'WFH':'Office'));
                if($r===5||$r===3)continue;
                $in=sprintf('%%s %%02d:%%02d:00',$day,8+($r%%3),($k*5+$r)%%60);$out=sprintf('%%s %%02d:%%02d:00',$day,16+($r%%2),($k*3)%%60);
                $vals[]=$wpdb->prepare('(%%d,1,%%s,%%s,%%s,%%s,%%s)',$eid,$day,$r===6?'late_sign_in':'sign_in',$in,'Office','app');
                if($r!==7)$vals[]=$wpdb->prepare('(%%d,1,%%s,%%s,%%s,%%s,%%s)',$eid,$day,'sign_out',$out,'Office','app');
                if($r===8)$vals[]=$wpdb->prepare('(%%d,1,%%s,%%s,%%s,%%s,%%s)',$eid,$day,'sign_in',$in,'Office','qr');   // the same second twice
                if(count($vals)>=2000){$wpdb->query("INSERT INTO {$p}ews_time_logs (employee_id,user_id,work_date,event_type,event_at,scheduled_status,source) VALUES ".implode(',',$vals));$n+=count($vals);$vals=[];}
            }
        }
        if($vals){$wpdb->query("INSERT INTO {$p}ews_time_logs (employee_id,user_id,work_date,event_type,event_at,scheduled_status,source) VALUES ".implode(',',$vals));$n+=count($vals);}
        foreach(array_chunk($sched,2000) as $c)$wpdb->query("INSERT INTO {$p}ews_schedule (employee_id,work_date,status) VALUES ".implode(',',$c));
        $wpdb->insert($p.'ews_company_calendar',['event_date'=>date('Y-m-d',$start+86400*20),'title'=>'National Day','event_type'=>'general_leave','active'=>1,'created_by'=>1]);
        echo $n;
    """ % {'start': START, 'end': END})


def mu(conf):
    """Turn the test mu-plugin on with a configuration (None = off)."""
    if conf is None:
        php("delete_option('ews_e2e_export'); @unlink(WPMU_PLUGIN_DIR.'/ews-e2e-export.php');")
        return
    php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); copy('%s',$d.'/ews-e2e-export.php'); update_option('ews_e2e_export',json_decode('%s',true),false); delete_option('ews_e2e_peak');"
        % (os.path.join(HERE, 'e2e_export_batches_mu.php'), __import__('json').dumps(conf)))


def raw(sess, path):
    try:
        r = sess.op.open(urllib.request.Request(B + path))
        return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


def link(page, action):
    m = re.search(r'href="([^"]*action=%s[^"]*)"' % action, page)
    return m.group(1).replace('&#038;', '&').replace('&amp;', '&').split('127.0.0.1:8080')[-1] if m else None


def parts(data):
    """The workbook's parts, with the time it was generated taken out."""
    try:
        z = zipfile.ZipFile(io.BytesIO(data))
    except Exception:
        return None
    return [(n, re.sub(rb'Generated: \d{4}-\d{2}-\d{2} \d{2}:\d{2}', b'Generated: -', z.read(n))) for n in z.namelist()]


def peak():
    # Read once: the wp-cli call that reads it writes its own peak when it ends (the mu-plugin is on there too).
    return int(php("echo (int)get_option('ews_e2e_peak');") or 0)


def ties_sorted(data):
    """The CSV with the records that tie exactly (same day, same name as the database compares it, same minute
    shown) put in one order: their order was never defined (docs/tasks/performance-2.md, open question 1)."""
    lines = data.decode('utf-8').split('\n')
    head, body = lines[:1], lines[1:]
    key = lambda l: (l.split(',')[0], (re.match(r'^[^,]*,[^,]*,("[^"]*"|[^,]*)', l) or [None, ''])[1].lower(), (re.search(r'"(\d{4}-\d{2}-\d{2} \d{2}:\d{2} [AP]M)"', l) or [None, ''])[1])
    out, group = [], []
    for l in body:
        if group and key(l) != key(group[-1]):
            out += sorted(group)
            group = []
        group.append(l)
    return '\n'.join(head + out + sorted(group))


print('time logs seeded:', seed())
adm = Session('admin', 'admin')

# ---------------------------------------------------------------- Sign In / Out CSV
TR = '/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s' % (START, END)
csv_url = link(adm.req(TR)[1], 'ews31_time_csv')
check('the Sign In / Out CSV link is there', bool(csv_url))
mu({'rows': 1000000000, 'peak': 1})
st, whole, h = raw(adm, csv_url)
whole_peak = peak()
check('one window (everything at once, as before) downloads', st == 200 and whole.startswith(b'\xef\xbb\xbfDate,Weekday') and whole.count(b'\n') > 5000, (st, whole[:200], whole.count(b'\n')))
for rows in (5000, 997, 3):
    mu({'rows': rows, 'peak': 1})
    st, part, h2 = raw(adm, csv_url)
    check('windows of %d records give the same records in the same order (exact ties aside: never defined)' % rows, st == 200 and len(part) == len(whole) and ties_sorted(part) == ties_sorted(whole), (st, len(part), len(whole)))
    check('...with the same headers', h2.get('Content-Type') == h.get('Content-Type') and h2.get('Content-Disposition') == h.get('Content-Disposition'), (h2, h))
    if rows == 997:
        p997 = peak()
        check('...and less memory than one window (%d vs %d bytes)' % (p997, whole_peak), 0 < p997 < whole_peak * 0.75, (p997, whole_peak))
one = link(adm.req('/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s' % (START, START))[1], 'ews31_time_csv')
mu({'rows': 3})
st, a, _ = raw(adm, one)
mu(None)
st2, b, _ = raw(adm, one)
check('one day: the same file in windows and without', st == st2 == 200 and a == b and a.count(b'\n') > 20, (st, st2, len(a), len(b)))
empty = link(adm.req('/wp-admin/admin.php?page=ews31-time-report&start=2001-01-01&end=2001-01-31')[1], 'ews31_time_csv')
st, e, _ = raw(adm, empty)
check('an empty period: the header only', st == 200 and e.count(b'\n') == 1, (st, e[:200]))
mu({'rows': 997, 'fail': 1})
st, f, hf = raw(adm, csv_url)
check('a window that cannot be read sends no file: an error page instead of a part', st != 200 and b'Date,Weekday' not in f and 'attachment' not in hf.get('Content-Disposition', ''), (st, f[:300], hf))
mu(None)

# ---------------------------------------------------------------- Report Center
for rtype in ('attendance', 'summary', 'timesheet', 'workforce'):
    for extra in ('', '&status=Late', '&employee_status=all'):
        if extra and rtype != 'attendance' and extra != '&employee_status=all':
            continue
        page = adm.req('/app/?ews_view=reports&report_type=%s&start=%s&end=%s%s' % (rtype, START, END, extra))[1]
        c_url, x_url = link(page, 'ews31_report_download'), link(page, 'ews31_report_xlsx')
        mu({'days': 1000000000})
        c1, x1 = raw(adm, c_url)[1] if c_url else b'', parts(raw(adm, x_url)[1]) if x_url else None
        mu({'days': 150})
        c2, x2 = raw(adm, c_url)[1] if c_url else b'', parts(raw(adm, x_url)[1]) if x_url else None
        mu({'days': 1})
        c3, x3 = raw(adm, c_url)[1] if c_url else b'', parts(raw(adm, x_url)[1]) if x_url else None
        label = rtype + (extra or '')
        check('%s CSV: one group and groups of employees give the same file' % label, c1.startswith(b'\xef\xbb\xbf') and c1.count(b'\n') > 2 and c1 == c2 == c3, (len(c1), len(c2), len(c3), c1[:200]))
        check('%s Excel: the same sheets, part by part' % label, x1 and x1 == x2 == x3, ([n for n, _ in x1 or []], [n for n, _ in x2 or []]))
mu(None)
page = adm.req('/app/?ews_view=reports&report_type=attendance&start=%s&end=%s' % (START, END))[1]
php("update_option('ews_working_days_e2e_saved',get_option('ews_working_days'),false); update_option('ews_working_days',[],false);")   # no working day: no row
x = parts(raw(adm, link(page, 'ews31_report_xlsx'))[1])
php("update_option('ews_working_days',get_option('ews_working_days_e2e_saved'),false); delete_option('ews_working_days_e2e_saved');")
sheet = dict(x or []).get('xl/worksheets/sheet1.xml', b'')
check('no rows: the Excel says so', b'No records found for the selected period.' in sheet, sheet[-400:])
tmp = php("echo count(glob(get_temp_dir().'workforce-one-*'));")
check('no temporary sheet is left behind', tmp == '0', tmp)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
