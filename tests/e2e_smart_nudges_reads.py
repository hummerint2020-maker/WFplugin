"""Smart Nudges job reads (3.31.160, production System health): the Sign In reminder's reads for every employee at once.

Before, the job read each employee's shift, today's schedule row, "attendance enabled" and today's Sign Ins one by one (about four
queries a person, every five minutes). Now it reads them for everyone in three queries. The reminders must stay exactly the same:
for each employee, the reminders built with the job's batch reads equal the ones built the old way, one by one.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_smart_nudges_reads.py <state-dir>
Wipes Workforce One test data; never run against a real site.
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import check, php, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
out = php("""
    $saved=get_option('ews_smart_nudges',null);$saved_shifts=get_option('ews_shifts',null);
    try{
    // Shifts (review 3.31.160): one active from an hour ago, one inactive (company hours), one overnight (its day may be yesterday).
    $h=(int)current_time('G');$from=sprintf('%02d:00',max(0,$h-1));
    update_option('ews_shifts',[['id'=>1,'name'=>'Standard','start'=>'08:00','end'=>'17:00','active'=>1],['id'=>7,'name'=>'Early','start'=>$from,'end'=>'23:59','active'=>1],
        ['id'=>8,'name'=>'Old','start'=>$from,'end'=>'23:59','active'=>0],['id'=>9,'name'=>'Night','start'=>'22:00','end'=>'06:00','overnight'=>1,'active'=>1]],false);
    update_option('ews_smart_nudges',['enabled'=>1,'items'=>['attendance'=>1,'tasks'=>0,'leave'=>1,'schedule'=>1],'attendance_after'=>1],false);
    $today=current_time('Y-m-d');$tomorrow=date('Y-m-d',strtotime($today.' +1 day'));
    $mk=function($login,$name,$status,$tracked=1,$shift=null)use($wpdb,$p,$today){
        $u=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        delete_user_meta($u,'ews_smart_nudge_dismissed');delete_user_meta($u,'ews_smart_nudge_pushed');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$u,'active'=>1,'attendance_enabled'=>$tracked,'default_shift_id'=>$shift]);$e=(int)$wpdb->insert_id;
        if($status!=='')$wpdb->insert($p.'ews_schedule',['employee_id'=>$e,'work_date'=>$today,'status'=>$status]);
        return $e;
    };
    $ids=[
        'office_not_signed'=>$mk('sn1','Office Not Signed','Office'),
        'office_signed'=>$mk('sn2','Office Signed','Office'),
        'late_signed'=>$mk('sn3','Late Signed','Office'),
        'no_schedule'=>$mk('sn4','No Schedule',''),
        'wfh'=>$mk('sn5','Working From Home','WFH'),
        'leave'=>$mk('sn6','On Leave','Vacation'),
        'not_tracked'=>$mk('sn7','Not Tracked','Office',0),
        'corrected'=>$mk('sn8','Corrected Away','Office'),
        'active_shift'=>$mk('sn9','Active Shift','Office',1,7),
        'inactive_shift'=>$mk('sn10','Inactive Shift','Office',1,8),
        'night_shift'=>$mk('sn11','Night Shift','Office',1,9),
    ];
    $yesterday=date('Y-m-d',strtotime($today.' -1 day'));
    $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids['night_shift'],'work_date'=>$yesterday,'status'=>'Office']);
    $wpdb->insert($p.'ews_time_logs',['employee_id'=>$ids['night_shift'],'user_id'=>1,'work_date'=>$yesterday,'event_type'=>'sign_in','event_at'=>$yesterday.' 22:05:00','scheduled_status'=>'Office']);   // an open night shift
    $log=function($e,$type,$extra=[])use($wpdb,$p,$today){$wpdb->insert($p.'ews_time_logs',$extra+['employee_id'=>$e,'user_id'=>1,'work_date'=>$today,'event_type'=>$type,'event_at'=>current_time('mysql'),'scheduled_status'=>'Office']);return (int)$wpdb->insert_id;};
    $log($ids['office_signed'],'sign_in');$log($ids['late_signed'],'late_sign_in');
    $first=$log($ids['corrected'],'sign_in');$log($ids['corrected'],'sign_out',['corrects_id'=>$first]);
    $wpdb->insert($p.'ews_schedule',['employee_id'=>$ids['office_signed'],'work_date'=>$tomorrow,'status'=>'WFH']);
    // The job's employees, read as the job reads them.
    $o=new EWS_Manager_V31_1();$rf=function($m)use($o){$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return $r;};
    $employees=$wpdb->get_results("SELECT id,name,wp_user_id FROM {$p}ews_employees WHERE active=1 AND wp_user_id>0 AND attendance_enabled=1 ORDER BY id ASC");
    $cfg=$rf('smart_nudge_settings')->invoke($o);
    // The job's way first, from empty request caches, counting its queries; then the old way, one employee at a time (no prefetch).
    $q0=$wpdb->num_queries;
    $pre=$rf('smart_nudges_prefetch')->invoke($o,$employees,$cfg);
    $new=[];foreach($employees as $e)$new[(int)$e->id]=$rf('smart_nudge_for_employee')->invoke($o,$e,(int)$e->wp_user_id,$pre);
    $q1=$wpdb->num_queries-$q0;
    // Forget what the job's reads put in the request caches, so the old way reads everything itself.
    foreach(['ews_shift_for_employee_cache','ews_working_hours_cache'] as $prop){$rp=new ReflectionProperty($o,$prop);$rp->setAccessible(true);$rp->setValue(null,[]);}
    $old=[];foreach($employees as $e)$old[(int)$e->id]=$rf('smart_nudge_for_employee')->invoke($o,$e,(int)$e->wp_user_id,null);
    // The whole job runs, once.
    delete_option('ews_smart_nudges_last_result');$o->smart_nudges_cron();
    $res=get_option('ews_smart_nudges_last_result');
    $night=(string)$rf('attendance_day')->invoke($o,$ids['night_shift']);
    echo wp_json_encode(['ids'=>$ids,'old'=>$old,'new'=>$new,'queries'=>$q1,'employees'=>count($employees),'result'=>$res,'night_day'=>$night,'today'=>$today,'early'=>current_time('H:i')<'00:02']);
    }finally{
        if($saved===null)delete_option('ews_smart_nudges');else update_option('ews_smart_nudges',$saved,false);
        if($saved_shifts===null)delete_option('ews_shifts');else update_option('ews_shifts',$saved_shifts,false);
        require_once ABSPATH.'wp-admin/includes/user.php';
        for($i=1;$i<=11;$i++)if($u=username_exists('sn'.$i))wp_delete_user($u);
        $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'sn%'");
    }
""")
r = json.loads(out.splitlines()[-1])
ids, old, new = r['ids'], r['old'], r['new']
kinds = lambda lst: sorted(n['kind'] for n in lst)
check('the job reads the employees with attendance tracked (not the untracked one)', str(ids['not_tracked']) not in new and len(new) == r['employees'] == 11, r['employees'])   # with the setup's own employee
for name in ['office_not_signed', 'office_signed', 'late_signed', 'no_schedule', 'wfh', 'leave', 'corrected', 'active_shift', 'inactive_shift', 'night_shift']:
    e = str(ids[name])
    check('%s: the same reminders with the reads done at once as one by one' % name, new.get(e) == old.get(e), (old.get(e), new.get(e)))
check('...an Office day without a Sign In gets the Sign In reminder', r['early'] or 'attendance' in kinds(new[str(ids['office_not_signed'])]))   # not in the first minutes after midnight
check('...an active shift from an hour ago too (the shift read for everyone at once)', r['early'] or 'attendance' in kinds(new[str(ids['active_shift'])]))
print('(the night shift\'s day is %s; today is %s: %s path)' % (r['night_day'], r['today'], 'the read-ahead' if r['night_day'] == r['today'] else 'the one-by-one'))
check('...a Sign In or a Late Sign In: no Sign In reminder', 'attendance' not in kinds(new[str(ids['office_signed'])]) and 'attendance' not in kinds(new[str(ids['late_signed'])]))
check('...a Sign In corrected away still counts as not signed in', 'attendance' in kinds(new[str(ids['corrected'])]))
check('...no schedule row or a leave day: no Sign In reminder', 'attendance' not in kinds(new[str(ids['no_schedule'])]) and 'attendance' not in kinds(new[str(ids['leave'])]))
check('...tomorrow\'s schedule still comes from the read ahead', 'schedule' in kinds(new[str(ids['office_signed'])]))
check('the reminders of 11 people take a handful of queries, not about four a person', r['queries'] <= 15, r['queries'])
check('the whole job completes and counts the reminders', isinstance(r['result'], dict) and r['result'].get('employees') == 11 and r['result'].get('nudges', 0) >= 3, r['result'])
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
