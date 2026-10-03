<?php
if (!defined('ABSPATH')) exit;

trait EWS_Achievements_Trait {

    private static $ews_achievements_schema_ready = false;
    private static $ews_achievement_definitions_cache = [];
    private $ews_achievement_evaluated = [];

    private function invalidate_achievement_definitions_cache(){
        self::$ews_achievement_definitions_cache=[];
    }

    private function ensure_achievements_schema(){
        if($this->ews_schema_is_current())return;
        if(self::$ews_achievements_schema_ready)return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $defs=$wpdb->prefix.'ews_achievements';
        $awards=$wpdb->prefix.'ews_employee_achievements';
        dbDelta("CREATE TABLE {$defs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NOT NULL,
            category VARCHAR(40) NOT NULL,
            rule_type VARCHAR(30) NOT NULL,
            threshold INT UNSIGNED NOT NULL DEFAULT 1,
            icon VARCHAR(20) NOT NULL DEFAULT '🏅',
            badge_style VARCHAR(20) NOT NULL DEFAULT 'circle',
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), UNIQUE KEY slug(slug), KEY category_active(category,active)
        ) {$c};");
        dbDelta("CREATE TABLE {$awards} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            achievement_id BIGINT UNSIGNED NOT NULL,
            earned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            metric_value INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY(id), UNIQUE KEY employee_achievement(employee_id,achievement_id),
            KEY employee_earned(employee_id,earned_at), KEY achievement_id(achievement_id)
        ) {$c};");

        $seed=[
            ['attendance-5','Perfect Start','5 consecutive working days with on-time attendance.','attendance','streak',5,'🏅','circle',10],
            ['attendance-10','Consistency','10 consecutive working days with on-time attendance.','attendance','streak',10,'🔥','shield',20],
            ['attendance-20','Attendance Pro','20 consecutive working days with on-time attendance.','attendance','streak',20,'💎','star',30],
            ['attendance-50','Attendance Master','50 consecutive working days with on-time attendance.','attendance','streak',50,'👑','ribbon',40],
            ['swap-1','Team Player','Helped a teammate by completing a successful schedule swap.','collaboration','swap_milestone',1,'🤝','circle',50],
            ['swap-5','Helpful Teammate','Completed 5 successful schedule swaps with teammates.','collaboration','swap_milestone',5,'🌟','shield',60],
            ['swap-10','Team Supporter','Completed 10 successful schedule swaps with teammates.','collaboration','swap_milestone',10,'🏆','star',70],
            ['swap-25','Collaboration Champion','Completed 25 successful schedule swaps with teammates.','collaboration','swap_milestone',25,'💎','ribbon',80],
        ];
        foreach($seed as $r){
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$defs} WHERE slug=%s LIMIT 1",$r[0]));
            if(!$exists){
                $wpdb->insert($defs,[
                    'slug'=>$r[0],'name'=>$r[1],'description'=>$r[2],'category'=>$r[3],
                    'rule_type'=>$r[4],'threshold'=>$r[5],'icon'=>$r[6],'badge_style'=>$r[7],'sort_order'=>$r[8],
                    'active'=>1,'created_at'=>current_time('mysql')
                ],['%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%s']);
            }
        }
        self::$ews_achievements_schema_ready=true;
    }

    private function achievements_enabled(){ return (bool)$this->option('ews_feature_achievements'); }

    private function achievement_definitions($category=''){
        if(!$this->achievements_enabled()) return [];
        $key=$category!==''?sanitize_key($category):'__all';
        if(array_key_exists($key,self::$ews_achievement_definitions_cache))return self::$ews_achievement_definitions_cache[$key];
        global $wpdb;
        $this->ensure_achievements_schema();
        $table=$wpdb->prefix.'ews_achievements';
        if($category){
            return self::$ews_achievement_definitions_cache[$key]=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE active=1 AND category=%s ORDER BY sort_order ASC,id ASC",sanitize_key($category)));
        }
        return self::$ews_achievement_definitions_cache[$key]=$wpdb->get_results("SELECT * FROM {$table} WHERE active=1 ORDER BY sort_order ASC,id ASC");
    }

    private function achievement_awarded($employee_id,$achievement_id){
        global $wpdb;
        $table=$wpdb->prefix.'ews_employee_achievements';
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE employee_id=%d AND achievement_id=%d LIMIT 1",$employee_id,$achievement_id));
    }

    private function award_achievement($employee_id,$achievement,$metric_value=0,$force=false,$skip_awarded_check=false){
        if(!$this->achievements_enabled() && !$force)return false;
        global $wpdb;
        if(!$employee_id || !$achievement || (!$skip_awarded_check && $this->achievement_awarded($employee_id,(int)$achievement->id)))return false;
        $emp=$wpdb->get_row($wpdb->prepare("SELECT id,name,wp_user_id FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
        if(!$emp)return false;
        $table=$wpdb->prefix.'ews_employee_achievements';
        $ok=$wpdb->insert($table,[
            'employee_id'=>(int)$employee_id,'achievement_id'=>(int)$achievement->id,
            'earned_at'=>current_time('mysql'),'metric_value'=>(int)$metric_value
        ],['%d','%d','%s','%d']);
        if($ok===false){
            if(stripos((string)$wpdb->last_error,'Duplicate')!==false)return false;
            if(method_exists($this,'audit')){
                $this->audit('achievement_award_failed','employee_achievement',0,
                    'emp='.(int)$employee_id.' ach='.(int)$achievement->id.' err='.$wpdb->last_error);
            }
            return false;
        }

        $title='🎉 Achievement Unlocked!';
        $message=sprintf('%s %s — %s',$achievement->icon,$achievement->name,$achievement->description);
        $uid=(int)$emp->wp_user_id;
        if($uid){
            $this->notify_user($uid,$title,$message,'achievement','achievement',(int)$achievement->id);
            $this->push_custom_notification($uid,$title,$message,'achievement',(int)$achievement->id,$this->notification_app_view_url('profile'));
        }
        $this->audit('achievement_earned','employee_achievement',(int)$wpdb->insert_id,sprintf('%s earned %s (%s)',$emp->name,$achievement->name,$achievement->slug));
        return true;
    }

    private function calc_streak_for_employee_from_data($employee_id,$cursor,$schedules,$logs,$company_leave,$working_days,$calendar_days){
        $count=0;
        $day_cursor=$cursor;
        $calendar_days=max(1,(int)$calendar_days);
        $working_days=array_map('intval',(array)$working_days);
        for($guard=0;$guard<$calendar_days;$guard++){
            $date=$day_cursor->format('Y-m-d');
            $day=(int)$day_cursor->format('w');
            if(in_array($day,$working_days,true)){
                $status=null;
                if(isset($schedules[$date])){
                    $row=$schedules[$date];
                    $status=is_object($row)?(string)$row->status:(string)$row;
                }
                if($status===null){
                    $day_cursor=$day_cursor->modify('-1 day');
                    continue;
                }
                if(in_array($status,['Vacation','Leave'],true) || isset($company_leave[$date]))break;
                if(in_array($status,['Office','WFH','Business Trip'],true)){
                    $in=null;$out=null;$late=false;
                    foreach($logs[$date]??[] as $ev){
                        if(($ev->event_type==='sign_in'||$ev->event_type==='late_sign_in') && !$in){$in=$ev->event_at;$late=$ev->event_type==='late_sign_in';}
                        if($ev->event_type==='sign_out' && !$out)$out=$ev->event_at;
                    }
                    if(!$in || !$out || $late)break;
                    $count++;
                }
            }
            $day_cursor=$day_cursor->modify('-1 day');
        }
        return $count;
    }

    private function achievement_attendance_streak($employee_id,$through_date){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id)return 0;
        try{$cursor=new DateTimeImmutable($through_date.' 12:00:00',wp_timezone());}catch(Exception $e){return 0;}
        $working_days=array_map('intval',(array)$this->option('ews_working_days'));
        if(!$working_days)return 0;
        $definitions=$this->achievement_definitions('attendance');
        $max_threshold=1;
        foreach((array)$definitions as $definition)$max_threshold=max($max_threshold,(int)$definition->threshold);
        $working_per_week=max(1,count($working_days));
        $calendar_days=max(7,min(370,(int)ceil(($max_threshold*7)/$working_per_week)+7));
        $start=$cursor->modify('-'.($calendar_days-1).' days')->format('Y-m-d');
        $end=$cursor->format('Y-m-d');
        $schedule_rows=$wpdb->get_results($wpdb->prepare("SELECT work_date,status FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s",$employee_id,$start,$end));
        $schedules=[]; foreach((array)$schedule_rows as $row)$schedules[(string)$row->work_date]=$row;
        $log_rows=$wpdb->get_results($wpdb->prepare("SELECT work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date BETWEEN %s AND %s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY work_date ASC,event_at ASC",$employee_id,$start,$end));
        $logs=[]; foreach((array)$log_rows as $row)$logs[(string)$row->work_date][]=$row;
        return $this->calc_streak_for_employee_from_data($employee_id,$cursor,$schedules,$logs,$this->company_leave_dates($start,$end),$working_days,$calendar_days);
    }

    private function achievement_evaluate_attendance($employee_id,$date){
        if(!$this->employee_attendance_enabled((int)$employee_id))return;
        $key=absint($employee_id).'|'.sanitize_text_field($date);
        if(isset($this->ews_achievement_evaluated[$key]))return;
        $this->ews_achievement_evaluated[$key]=true;
        $streak=$this->achievement_attendance_streak($employee_id,$date);
        if($streak<=0)return;
        $achievements=$this->achievement_definitions('attendance');
        $eligible=[];
        $ids=[];
        foreach($achievements as $achievement){
            if((int)$achievement->threshold <= $streak){$eligible[]=$achievement;$ids[]=(int)$achievement->id;}
        }
        if(!$eligible)return;
        global $wpdb;
        $table=$wpdb->prefix.'ews_employee_achievements';
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $args=array_merge([absint($employee_id)],$ids);
        $sql=$wpdb->prepare("SELECT achievement_id FROM {$table} WHERE employee_id=%d AND achievement_id IN ({$placeholders})",$args);
        $awarded=array_map('intval',(array)$wpdb->get_col($sql));
        $awarded=array_fill_keys($awarded,true);
        foreach($eligible as $achievement){
            if(isset($awarded[(int)$achievement->id]))continue;
            $this->award_achievement($employee_id,$achievement,$streak,false,true);
        }
    }

    private function achievement_evaluate_swap_milestones($employee_id){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id || !$this->achievements_enabled())return 0;
        $this->ensure_achievements_schema();
        $table=$wpdb->prefix.'ews_schedule_swaps';
        $count=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE target_employee_id=%d AND status='Accepted'",$employee_id
        ));
        if($count<=0)return 0;
        $achievements=$this->achievement_definitions('collaboration');
        $eligible=[];$ids=[];
        foreach((array)$achievements as $achievement){
            if((int)$achievement->threshold <= $count){$eligible[]=$achievement;$ids[]=(int)$achievement->id;}
        }
        if(!$eligible)return 0;
        $awards_table=$wpdb->prefix.'ews_employee_achievements';
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $args=array_merge([$employee_id],$ids);
        $sql=$wpdb->prepare("SELECT achievement_id FROM {$awards_table} WHERE employee_id=%d AND achievement_id IN ({$placeholders})",$args);
        $awarded=array_fill_keys(array_map('intval',(array)$wpdb->get_col($sql)),true);
        $awarded_count=0;
        foreach($eligible as $achievement){
            if(isset($awarded[(int)$achievement->id]))continue;
            if($this->award_achievement($employee_id,$achievement,$count,false,true))$awarded_count++;
        }
        return $awarded_count;
    }

    private function achievement_evaluate_swap_acceptance($target_employee_id,$requester_employee_id,$swap_id){
        global $wpdb;
        $table=$wpdb->prefix.'ews_schedule_swaps';
        $r=$wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id=%d AND status='Accepted' LIMIT 1",
            absint($swap_id)
        ));
        if(!$r)return;
        $this->achievement_evaluate_swap_milestones($target_employee_id);
    }

    private function create_manual_achievement($employee_id,$name,$description,$icon,$badge_style){
        global $wpdb;
        $this->ensure_achievements_schema();
        $slug='manual-'.wp_generate_uuid4();
        $defs=$wpdb->prefix.'ews_achievements';
        $ok=$wpdb->insert($defs,[
            'slug'=>$slug,'name'=>$name,'description'=>$description,'category'=>'recognition','rule_type'=>'manual','threshold'=>1,
            'icon'=>$icon,'badge_style'=>$badge_style,'sort_order'=>999,'active'=>1,'created_at'=>current_time('mysql')
        ],['%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%s']);
        if($ok===false)return false;
        $this->invalidate_achievement_definitions_cache();
        $achievement=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$defs} WHERE id=%d LIMIT 1",(int)$wpdb->insert_id));
        if(!$achievement)return false;
        return $this->award_achievement((int)$employee_id,$achievement,1,true);
    }

    private function achievement_admin_progress_data($employees,$defs){
        global $wpdb;
        $employees=(array)$employees;
        $defs=(array)$defs;
        $result=[];
        if(!$employees)return $result;

        $attendance_defs=[];$swap_defs=[];
        foreach($defs as $d){
            $d->threshold=(int)$d->threshold;
            if($d->category==='attendance')$attendance_defs[]=$d;
            if($d->category==='collaboration')$swap_defs[]=$d;
        }
        usort($attendance_defs,function($a,$b){return (int)$a->threshold <=>(int)$b->threshold;});
        usort($swap_defs,function($a,$b){return (int)$a->threshold <=>(int)$b->threshold;});

        $ids=array_map(function($e){return (int)$e->id;},$employees);
        $id_map=array_fill_keys($ids,true);
        $earned=[];
        if($ids){
            $awards=$wpdb->get_results("SELECT employee_id,achievement_id,metric_value,earned_at FROM {$wpdb->prefix}ews_employee_achievements WHERE employee_id IN (".implode(',',array_map('absint',$ids)).") ORDER BY earned_at DESC,id DESC");
            foreach((array)$awards as $a){
                $eid=(int)$a->employee_id;$aid=(int)$a->achievement_id;
                if(!isset($id_map[$eid]))continue;
                if(!isset($earned[$eid]))$earned[$eid]=[];
                $earned[$eid][$aid]=['metric_value'=>(int)$a->metric_value,'earned_at'=>$a->earned_at];
            }
        }

        $swap_counts=[];
        if($ids){
            $rows=$wpdb->get_results("SELECT target_employee_id AS employee_id,COUNT(*) AS total FROM {$wpdb->prefix}ews_schedule_swaps WHERE status='Accepted' AND target_employee_id IN (".implode(',',array_map('absint',$ids)).") GROUP BY target_employee_id");
            foreach((array)$rows as $r)$swap_counts[(int)$r->employee_id]=(int)$r->total;
        }

        // Reproduce the existing attendance-streak rules in one batched read so the
        // admin page does not execute hundreds of per-day queries for every employee.
        $attendance_streaks=[];
        if($ids){
            $working_days=array_map('intval',(array)$this->option('ews_working_days'));
            $today=current_time('Y-m-d');
            try{
                $tz=wp_timezone();
                $cursor=new DateTimeImmutable($today.' 12:00:00',$tz);
            }catch(Exception $e){
                return $result;
            }
            $max_threshold=1;
            foreach($attendance_defs as $definition)$max_threshold=max($max_threshold,(int)$definition->threshold);
            $working_per_week=max(1,count($working_days));
            $calendar_days=max(7,min(370,(int)ceil(($max_threshold*7)/$working_per_week)+7));
            $start=$cursor->modify('-'.($calendar_days-1).' days')->format('Y-m-d');
            $end=$cursor->format('Y-m-d');
            $schedules=[];
            $schedule_rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE employee_id IN (".implode(',',array_map('absint',$ids)).") AND work_date BETWEEN %s AND %s",$start,$today));
            foreach((array)$schedule_rows as $r)$schedules[(int)$r->employee_id][$r->work_date]=(string)$r->status;

            $logs=[];
            $log_rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN (".implode(',',array_map('absint',$ids)).") AND work_date BETWEEN %s AND %s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY event_at ASC",$start,$today));
            foreach((array)$log_rows as $r){
                $eid=(int)$r->employee_id;$date=(string)$r->work_date;
                if(!isset($logs[$eid]))$logs[$eid]=[];
                if(!isset($logs[$eid][$date]))$logs[$eid][$date]=[];
                $logs[$eid][$date][]=$r;
            }

            $company_leave=[];
            foreach((array)$this->company_leave_dates($start,$end) as $leave_date=>$leave_title)$company_leave[(string)$leave_date]=true;

            foreach($ids as $eid){
                $attendance_streaks[$eid]=$this->calc_streak_for_employee_from_data(
                    $eid,$cursor,$schedules[$eid]??[],$logs[$eid]??[],$company_leave,$working_days,$calendar_days
                );
            }
        }

        foreach($employees as $employee){
            $eid=(int)$employee->id;
            $attendance=(int)($attendance_streaks[$eid]??0);
            $swaps=(int)($swap_counts[$eid]??0);
            $earned_for=$earned[$eid]??[];
            $next_att=null;$next_swap=null;
            foreach($attendance_defs as $d){
                if(!isset($earned_for[(int)$d->id]) && (int)$d->threshold>$attendance){$next_att=$d;break;}
            }
            foreach($swap_defs as $d){
                if(!isset($earned_for[(int)$d->id]) && (int)$d->threshold>$swaps){$next_swap=$d;break;}
            }
            // If a milestone was manually/otherwise earned ahead of the metric, choose
            // the next unearned milestone rather than showing a negative/zero target.
            if(!$next_att){foreach($attendance_defs as $d){if(!isset($earned_for[(int)$d->id])){$next_att=$d;break;}}}
            if(!$next_swap){foreach($swap_defs as $d){if(!isset($earned_for[(int)$d->id])){$next_swap=$d;break;}}}

            $result[$eid]=[
                'employee'=>$employee,
                'attendance'=>[
                    'current'=>$attendance,
                    'target'=>$next_att?(int)$next_att->threshold:0,
                    'remaining'=>$next_att?max(0,(int)$next_att->threshold-$attendance):0,
                    'achievement'=>$next_att,
                    'earned'=>$earned_for,
                ],
                'swap'=>[
                    'current'=>$swaps,
                    'target'=>$next_swap?(int)$next_swap->threshold:0,
                    'remaining'=>$next_swap?max(0,(int)$next_swap->threshold-$swaps):0,
                    'achievement'=>$next_swap,
                    'earned'=>$earned_for,
                ],
            ];
        }
        return $result;
    }

    private function employee_achievements($employee_id){
        if(!$this->achievements_enabled())return [];
        // Low-frequency fallback only. The normal award path runs on swap acceptance;
        // this catches missed awards without adding swap-count queries to every profile view.
        $heal_key='ews_swap_milestone_check_'.absint($employee_id);
        if(!get_transient($heal_key)){
            $this->achievement_evaluate_swap_milestones((int)$employee_id);
            set_transient($heal_key,1,HOUR_IN_SECONDS);
        }
        global $wpdb;
        $this->ensure_achievements_schema();
        $defs=$wpdb->prefix.'ews_achievements';$awards=$wpdb->prefix.'ews_employee_achievements';
        return $wpdb->get_results($wpdb->prepare("SELECT a.*,d.slug,d.name,d.description,d.category,d.rule_type,d.threshold,d.icon,d.badge_style FROM {$awards} a INNER JOIN {$defs} d ON d.id=a.achievement_id WHERE a.employee_id=%d ORDER BY a.earned_at DESC,a.id DESC",$employee_id));
    }

    private function achievement_count($employee_id){
        global $wpdb;
        $this->ensure_achievements_schema();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_employee_achievements WHERE employee_id=%d",$employee_id));
    }
}
